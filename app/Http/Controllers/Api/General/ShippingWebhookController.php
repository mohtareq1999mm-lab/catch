<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\General;

use App\DTOs\Shipping\ShippingWebhookIngestResult;
use App\Enums\Shipping\ShippingWebhookIngestOutcome;
use App\Http\Controllers\Controller;
use App\Services\Shipment\ShippingWebhookIngestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Marvel\Traits\ApiResponse;

/**
 * #143: public, provider-signed shipping webhook inbox.
 *
 * This endpoint only extracts request inputs, delegates to the application
 * ingest service, and maps the result to the standard API envelope. The
 * service owns limiter -> verification -> durable insert ordering; no
 * shipment, order, fulfillment, payment, or inventory mutation happens here.
 */
class ShippingWebhookController extends Controller
{
    use ApiResponse;

    public function __construct(private readonly ShippingWebhookIngestService $ingest) {}

    public function handle(Request $request, string $provider): JsonResponse
    {
        $result = $this->ingest->ingest(
            $provider,
            $request->ip() ?? 'unknown',
            $request->getContent(),
            $request->headers->all(),
        );

        $outcome = $result->outcome;
        $response = $this->apiResponse(
            __($outcome->messageKey()),
            $outcome->httpStatus(),
            $outcome->success(),
            $this->responseData($result),
        );

        if ($result->retryAfter !== null) {
            $retryAfter = max(1, $result->retryAfter);
            $response->header('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /** @return array<string,mixed>|null */
    private function responseData(ShippingWebhookIngestResult $result): ?array
    {
        return match ($result->outcome) {
            ShippingWebhookIngestOutcome::RATE_LIMITED => ['retry_after' => max(1, $result->retryAfter ?? 1)],
            ShippingWebhookIngestOutcome::UNAVAILABLE => $result->retryAfter === null
                ? null
                : ['retry_after' => max(1, $result->retryAfter)],
            ShippingWebhookIngestOutcome::DUPLICATE => ['status' => 'duplicate'],
            ShippingWebhookIngestOutcome::ACCEPTED => [
                'status' => 'accepted',
                'event_id' => $result->eventKey,
            ],
            ShippingWebhookIngestOutcome::INVALID_SIGNATURE,
            ShippingWebhookIngestOutcome::INVALID_PAYLOAD => null,
        };
    }
}
