<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * #156: the identity path that established a settlement line's order link.
 */
enum CodSettlementMatchMethod: string
{
    case AWB = 'awb';
    case PROVIDER_SHIPMENT_ID = 'provider_shipment_id';
    case ORDER_NUMBER = 'order_number';
    case ORDER_ID = 'order_id';
    case MANUAL = 'manual';
}
