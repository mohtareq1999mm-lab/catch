<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

final class ShippingOutboxOperation
{
    /** @param array<string,mixed> $payload */
    public function __construct(
        public readonly string $operation,
        public readonly string $aggregateType,
        public readonly string $aggregateId,
        public readonly string $idempotencyKey,
        public readonly ?string $providerCode = null,
        public readonly ?string $providerMode = null,
        public readonly array $payload = [],
    ) {}
}
