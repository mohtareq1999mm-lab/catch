<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\CarrierWebhookEvent;
use App\DTOs\Shipping\ShippingWebhookIngestResult;
use App\Enums\Shipping\ShippingWebhookIngestOutcome;
use App\Enums\Shipping\WebhookRateLimitOutcome;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\InvalidCarrierWebhookPayload;
use App\Exceptions\InvalidCarrierWebhookSignature;

/**
 * #143 webhook workflow: limiter -> verification -> durable insert.
 *
 * Application-owned orchestration only. This service never mutates shipment,
 * order, fulfillment, payment, or inventory state and makes no outbound call.
 * Polling the existing tracking surface remains the fallback when callbacks
 * are unavailable; the inbox makes replay safe for the later polling worker.
 */
final class ShippingWebhookIngestService
{
    public function __construct(
        private readonly CarrierWebhookRateLimiter $limiter,
        private readonly CarrierWebhookVerifier $verifier,
        private readonly ShippingWebhookInbox $inbox,
    ) {}

    /**
     * @param array<string,mixed> $headers Lower-cased HTTP header map from the request.
     * @param array<string,mixed> $server  Raw server vars for trusted proxy source-IP derivation.
     */
    public function ingest(
        string $providerCode,
        string $remoteAddr,
        string $rawPayload,
        array $headers = [],
        array $server = [],
    ): ShippingWebhookIngestResult {
        $limiterResult = $this->limiterResult($providerCode, $remoteAddr, $server);
        if ($limiterResult !== null) {
            return $limiterResult;
        }

        $event = $this->verifyEvent($providerCode, $rawPayload, $headers);
        if ($event instanceof ShippingWebhookIngestResult) {
            return $event;
        }

        return $this->storeEvent($event);
    }

    /**
     * @param array<string,mixed> $server
     */
    private function limiterResult(string $providerCode, string $remoteAddr, array $server): ?ShippingWebhookIngestResult
    {
        // #153: source IP is derived only through configured trusted proxies.
        $decision = $this->limiter->evaluate($providerCode, $remoteAddr, $server);

        return match ($decision['outcome']) {
            WebhookRateLimitOutcome::UNAVAILABLE => new ShippingWebhookIngestResult(
                ShippingWebhookIngestOutcome::UNAVAILABLE,
                retryAfter: $decision['retry_after'],
            ),
            WebhookRateLimitOutcome::LIMITED => new ShippingWebhookIngestResult(
                ShippingWebhookIngestOutcome::RATE_LIMITED,
                retryAfter: $decision['retry_after'],
            ),
            WebhookRateLimitOutcome::ALLOWED => null,
        };
    }

    /** @param array<string,mixed> $headers */
    private function verifyEvent(
        string $providerCode,
        string $rawPayload,
        array $headers,
    ): CarrierWebhookEvent|ShippingWebhookIngestResult {
        try {
            $header = $this->verifier->signatureHeader($providerCode);

            return $this->verifier->verify(
                $providerCode,
                $rawPayload,
                $this->presentedSignature($headers, $header),
            );
        } catch (InvalidCarrierWebhookSignature) {
            return new ShippingWebhookIngestResult(ShippingWebhookIngestOutcome::INVALID_SIGNATURE);
        } catch (InvalidCarrierWebhookPayload) {
            return new ShippingWebhookIngestResult(ShippingWebhookIngestOutcome::INVALID_PAYLOAD);
        } catch (CarrierConfigurationException) {
            // Misconfiguration must fail closed; there is nothing safe to retry.
            return new ShippingWebhookIngestResult(ShippingWebhookIngestOutcome::UNAVAILABLE);
        }
    }

    private function storeEvent(CarrierWebhookEvent $event): ShippingWebhookIngestResult
    {
        // #160: internal delivery actions/events must never create external
        // inbox rows. Internal mode has no external webhook contract.
        if ($this->isInternalEvent($event)) {
            return new ShippingWebhookIngestResult(ShippingWebhookIngestOutcome::UNAVAILABLE);
        }

        // The row is committed before the HTTP layer acknowledges acceptance.
        // A scoped-key collision is a successful no-op, never a second pass.
        $record = $this->inbox->store($event);

        if ($record === null) {
            return new ShippingWebhookIngestResult(ShippingWebhookIngestOutcome::DUPLICATE);
        }

        return new ShippingWebhookIngestResult(
            ShippingWebhookIngestOutcome::ACCEPTED,
            eventKey: $record->event_key,
        );
    }

    private function isInternalEvent(CarrierWebhookEvent $event): bool
    {
        return $event->mode === 'internal' || $event->providerCode === 'internal';
    }

    /** @param array<string,mixed> $headers */
    private function presentedSignature(array $headers, string $header): ?string
    {
        $values = $headers[strtolower($header)] ?? null;
        $signature = is_array($values) ? reset($values) : $values;

        return is_string($signature) ? $signature : null;
    }
}

