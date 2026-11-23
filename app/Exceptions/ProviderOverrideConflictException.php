<?php

declare(strict_types=1);

namespace App\Exceptions;

use RuntimeException;

/**
 * #148: an AWB is a provider-visible commitment. It cannot be reassigned in
 * place; cancel the shipment and create a replacement with an audited override.
 */
final class ProviderOverrideConflictException extends RuntimeException
{
    public static function awbAlreadyCreated(int $shipmentId): self
    {
        return new self("Shipment [{$shipmentId}] already has an AWB; cancel and replace it instead of overriding.");
    }
}
