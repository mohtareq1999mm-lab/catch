<?php

declare(strict_types=1);

namespace App\Services\Payment;

use Stripe\Event;
use Stripe\Webhook;

/**
 * Thin seam over \Stripe\Webhook::constructEvent.
 *
 * Production delegates to the real SDK (offline HMAC check, no network).
 * Tests exercise the same path with a test webhook secret and locally
 * generated signatures — never against the live API.
 */
class StripeWebhookVerifier
{
    public function construct(string $payload, string $sigHeader, string $secret): Event
    {
        return Webhook::constructEvent($payload, $sigHeader, $secret);
    }
}
