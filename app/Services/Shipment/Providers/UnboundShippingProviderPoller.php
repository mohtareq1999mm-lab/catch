<?php

declare(strict_types=1);

namespace App\Services\Shipment\Providers;

use App\Contracts\Shipping\ShippingProviderPoller;
use Illuminate\Contracts\Container\BindingResolutionException;

/**
 * #169 fail-closed default: polling is a no-op until a production poller is bound.
 *
 * Throwing inside poll() (rather than at container resolution) lets the polling
 * service persist the error state and lets the scheduled command report FAILURE.
 */
final class UnboundShippingProviderPoller implements ShippingProviderPoller
{
    public function poll(string $providerCode, string $mode, string $accountCode, ?string $cursor): object
    {
        throw new BindingResolutionException(
            'No production ShippingProviderPoller is bound; cannot poll '
            .$providerCode.'/'.$mode.'/'.$accountCode.'.'
        );
    }
}
