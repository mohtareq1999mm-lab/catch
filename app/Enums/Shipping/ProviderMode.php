<?php

declare(strict_types=1);

namespace App\Enums\Shipping;

use App\Support\ProviderCode;

/**
 * Shipping carrier execution modes. These values are stored verbatim in
 * configuration, shipment snapshots and provider evidence.
 */
enum ProviderMode: string
{
    case SANDBOX = 'sandbox';
    case PRODUCTION = 'production';
    case INTERNAL = 'internal';

    /**
     * #175: internal mode is reserved for the built-in local-delivery provider.
     */
    public function isAllowedFor(string $providerCode): bool
    {
        return $this === self::INTERNAL
            ? $providerCode === ProviderCode::INTERNAL
            : $providerCode !== ProviderCode::INTERNAL;
    }
}
