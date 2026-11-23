<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * #151: fixed operational severity for internal shipment exceptions.
 */
enum ShipmentExceptionSeverity: string
{
    case HIGH = 'high';
}
