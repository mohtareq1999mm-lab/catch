<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * #151: lifecycle states of an internal shipment exception.
 */
enum ShipmentExceptionStatus: string
{
    case OPEN = 'open';
}
