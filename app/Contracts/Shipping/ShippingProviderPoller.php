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
     * @return object{events:list<\App\DTOs\Shipping\CarrierWebhookEvent>,nextCursor:?string,hasMore:bool}
     */
    public function poll(string $providerCode, string $mode, string $accountCode, ?string $cursor): object;
}
