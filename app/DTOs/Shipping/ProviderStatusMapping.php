<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;

/**
 * #151: result of applying a versioned provider status map to a raw status.
 *
 * A mapping either yields exactly one canonical shipment status or an internal
 * exception classification. Both outcomes retain the mapping version used.
 */
final class ProviderStatusMapping
{
    public function __construct(
        public readonly ?ShipmentStatus $mappedStatus,
        public readonly string $mappingVersion,
        public readonly ?ProviderEventExceptionType $exceptionType,
    ) {}

    public function canTransition(): bool
    {
        return $this->mappedStatus !== null && $this->exceptionType === null;
    }
}
