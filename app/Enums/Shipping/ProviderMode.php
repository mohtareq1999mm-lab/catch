<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

/**
 * Shipping carrier execution modes. These values are stored verbatim in
 * configuration, shipment snapshots and provider evidence.
 */
enum ProviderMode: string
{
    case SANDBOX = 'sandbox';
    case PRODUCTION = 'production';
    case INTERNAL = 'internal';
}
