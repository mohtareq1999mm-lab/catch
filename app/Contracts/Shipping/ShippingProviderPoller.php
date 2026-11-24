<?php

declare(strict_types=1);

namespace App\Contracts\Shipping;

/**
 * #169: optional provider polling capability.
 *
 * Polling must return normalized CarrierWebhookEvent objects; the polling
 * service owns inbox insertion, dedupe, and durable cursor advancement.
 */
interface ShippingProviderPoller
{
    /**
     * Providers are untrusted at this boundary. The polling service validates
     * the response shape before any inbox or cursor write.
     *
     * @return mixed expected object{events:list<\App\DTOs\Shipping\CarrierWebhookEvent>,nextCursor:?string,hasMore:bool}
     */
    public function poll(string $providerCode, string $mode, string $accountCode, ?string $cursor): mixed;
}
