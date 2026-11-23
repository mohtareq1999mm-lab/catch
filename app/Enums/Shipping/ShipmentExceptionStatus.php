<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * #151/#145: lifecycle states of an internal shipment exception.
 * Investigation is internal only and never mutates shipment/order/payment state.
 */
enum ShipmentExceptionStatus: string
{
    case OPEN = 'open';
    case INVESTIGATING = 'investigating';
    case RESOLVED = 'resolved';
    case DISMISSED = 'dismissed';
}
