<?php

declare(strict_types=1);

namespace App\Services\Shipment\Outbox;

use App\Enums\Shipping\ShippingOutboxOperationType;
use InvalidArgumentException;

final class ShippingOutboxOperation
{
    public readonly ShippingOutboxOperationType $operation;

    public readonly string $aggregateType;

    public readonly string $aggregateId;

    /**
     * Opaque identity of the authorized business request. Retries reuse it;
     * a new version requires a new one.
     */
    public readonly string $authorizedRequestId;

    public readonly int $version;

    public readonly ?string $providerCode;

    public readonly ?string $providerMode;

    /** @var array<string,int|string> */
    public readonly array $payload;

    /**
     * Deterministic identity: operation:aggregate_type:aggregate_id:version.
     *
     * The service cannot authorize a business request itself; the upstream
     * caller must derive authorizedRequestId only after its own authorization
     * check. Retries reuse the same version; a new version requires a distinct
     * authorized request identity and is rejected otherwise.
     */
    public readonly string $idempotencyKey;

    /** @param array<mixed> $payload */
    public function __construct(
        ShippingOutboxOperationType|string $operation,
        string $aggregateType,
        string $aggregateId,
        string $authorizedRequestId,
        int $version = 1,
        ?string $providerCode = null,
        ?string $providerMode = null,
        array $payload = [],
    ) {
        $this->operation = is_string($operation)
            ? ShippingOutboxOperationType::from($operation)
            : $operation;

        if (preg_match('/^[a-z][a-z0-9_]*$/', $aggregateType) !== 1 || strlen($aggregateType) > 60) {
            throw new InvalidArgumentException('Invalid shipping outbox aggregate type.');
        }

        ShippingOutboxPayload::assertSafeIdentifier($aggregateId, 'aggregate id', 64);
        if (str_contains($aggregateId, ':')) {
            throw new InvalidArgumentException('Invalid shipping outbox aggregate id.');
        }
        ShippingOutboxPayload::assertSafeIdentifier($authorizedRequestId, 'authorized request id', 64);

        if ($version < 1) {
            throw new InvalidArgumentException('Shipping outbox version must be at least 1.');
        }

        if ($providerCode !== null && ($providerCode === '' || strlen($providerCode) > 32)) {
            throw new InvalidArgumentException('Invalid shipping outbox provider code.');
        }

        if ($providerMode !== null && ($providerMode === '' || strlen($providerMode) > 16)) {
            throw new InvalidArgumentException('Invalid shipping outbox provider mode.');
        }

        $this->aggregateType = $aggregateType;
        $this->aggregateId = $aggregateId;
        $this->authorizedRequestId = $authorizedRequestId;
        $this->version = $version;
        $this->providerCode = $providerCode;
        $this->providerMode = $providerMode;
        $this->payload = ShippingOutboxPayload::redact($payload);
        $this->idempotencyKey = $this->operation->value.':'.$aggregateType.':'.$aggregateId.':'.$version;
    }

    public function operationKey(): string
    {
        return $this->idempotencyKey;
    }
}
