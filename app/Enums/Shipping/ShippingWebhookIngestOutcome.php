<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * HTTP-facing result of the webhook ingest workflow.
 *
 * The enum owns status/translation mapping so the controller only serialises
 * the result; it does not make limiter, verification, or persistence decisions.
 */
enum ShippingWebhookIngestOutcome: string
{
    case ACCEPTED = 'accepted';
    case DUPLICATE = 'duplicate';
    case INVALID_SIGNATURE = 'invalid_signature';
    case INVALID_PAYLOAD = 'invalid_payload';
    case UNAVAILABLE = 'unavailable';
    case RATE_LIMITED = 'rate_limited';

    public function httpStatus(): int
    {
        return match ($this) {
            self::ACCEPTED, self::DUPLICATE => 200,
            self::INVALID_SIGNATURE => 401,
            self::INVALID_PAYLOAD => 400,
            self::UNAVAILABLE => 503,
            self::RATE_LIMITED => 429,
        };
    }

    public function messageKey(): string
    {
        return 'shipping.webhook_'.$this->value;
    }

    public function success(): bool
    {
        return $this === self::ACCEPTED || $this === self::DUPLICATE;
    }
}
