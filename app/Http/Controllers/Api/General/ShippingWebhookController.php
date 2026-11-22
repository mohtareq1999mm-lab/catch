<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\General;

use App\Enums\Shipping\WebhookRateLimitOutcome;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\InvalidCarrierWebhookPayload;
use App\Exceptions\InvalidCarrierWebhookSignature;
use App\Http\Controllers\Controller;
use App\Services\Shipment\CarrierWebhookRateLimiter;
use App\Services\Shipment\CarrierWebhookVerifier;
use App\Services\Shipment\ShippingWebhookInbox;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

/**
 * #143: public, provider-signed shipping webhook inbox.
 *
 * Order of operations is deliberate: limiter -> raw-body HMAC -> durable
 * insert -> acknowledgement. This endpoint performs NO shipment, order,
 * fulfillment, payment, or inventory mutation and makes NO outbound call.
 * Polling the existing tracking surface remains the fallback when callbacks
 * are unavailable; the inbox makes replay safe for the later polling worker.
 */
class ShippingWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(
        private readonly CarrierWebhookRateLimiter $limiter,
        private readonly CarrierWebhookVerifier $verifier,
        private readonly ShippingWebhookInbox $inbox,
    ) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        // Source IP is derived from $request->ip(), which honours the global
        // TrustProxies configuration. This endpoint deliberately does NOT
        // change that global setting: if the trusted-proxy list is wrong, a
        // client-controlled X-Forwarded-For can select the limiter bucket.
        // Deployment must keep the trusted-proxy list correct; no per-route
        // proxy guessing is safe here.
        $decision = $this->limiter->evaluate($provider, $request->ip() ?? 'unknown');

        if ($decision['outcome'] === WebhookRateLimitOutcome::UNAVAILABLE) {
            return $this->rateLimitedResponse(503, $decision['retry_after'], __('shipping.webhook_unavailable'));
        }
        if ($decision['outcome'] === WebhookRateLimitOutcome::LIMITED) {
            return $this->rateLimitedResponse(429, $decision['retry_after'], __('shipping.webhook_rate_limited'));
        }

        try {
            $header = $this->verifier->signatureHeader($provider);
            $event = $this->verifier->verify(
                $provider,
                $request->getContent(),
                $request->header($header),
            );
        } catch (InvalidCarrierWebhookSignature) {
            return $this->apiResponse(__('shipping.webhook_invalid_signature'), 401, false);
        } catch (InvalidCarrierWebhookPayload) {
            return $this->apiResponse(__('shipping.webhook_invalid_payload'), 400, false);
        } catch (CarrierConfigurationException) {
            return $this->apiResponse(__('shipping.webhook_unavailable'), 503, false);
        }

        // The row is committed before this response is returned. A duplicate
        // returns a successful no-op without touching any other state.
        $record = $this->inbox->store($event);

        if ($record === null) {
            return $this->apiResponse(__('shipping.webhook_duplicate'), 200, true, [
                'status' => 'duplicate',
            ]);
        }

        return $this->apiResponse(__('shipping.webhook_accepted'), 200, true, [
            'status' => 'accepted',
            'event_id' => $record->event_key,
        ]);
    }

    private function rateLimitedResponse(int $status, int $retryAfter, string $message): JsonResponse
    {
        $retryAfter = max(1, $retryAfter);

        return $this->apiResponse($message, $status, false, [
            'retry_after' => $retryAfter,
        ])->header('Retry-After', (string) $retryAfter);
    }
}
