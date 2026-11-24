<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Enums\Shipping\ShippingOutboxStatus;

final class ShippingOutboxResult
{
    /** @param array<string,mixed>|null $payload */
    public function __construct(
        public readonly ShippingOutboxStatus $status,
        public readonly ?array $payload = null,
        public readonly ?string $error = null,
    ) {}

    /** @param array<string,mixed>|null $payload */
    public static function succeeded(?array $payload = null): self
    {
        return new self(ShippingOutboxStatus::SUCCEEDED, $payload);
    }

    public static function failed(string $error): self
    {
        return new self(ShippingOutboxStatus::FAILED, null, $error);
    }

    public static function unknown(string $error): self
    {
        return new self(ShippingOutboxStatus::UNKNOWN, null, $error);
    }

    /**
     * Explicit reconciler signal: provider lookup proved the external operation
     * does not exist, so it is safe to retry through the normal processing path.
     */
    public static function retry(?string $reason = null): self
    {
        return new self(ShippingOutboxStatus::PENDING, null, $reason);
    }
}
