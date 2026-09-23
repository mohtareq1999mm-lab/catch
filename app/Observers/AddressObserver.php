<?php

namespace App\Observers;

use App\Events\Coupons\UserAddressChanged;
use Marvel\Database\Models\Address;

/**
 * Saved-address lifecycle → distribution relevance signal.
 * Fires on create/delete and on governorate reassignment only — noop
 * touches never fan out (the transition layer would dedupe them anyway).
 */
class AddressObserver
{
    public function created(Address $address): void
    {
        event(new UserAddressChanged((int) $address->customer_id, (int) $address->getKey()));
    }

    public function updated(Address $address): void
    {
        if ($address->wasChanged('governorate_id')) {
            event(new UserAddressChanged((int) $address->customer_id, (int) $address->getKey()));
        }
    }

    public function deleted(Address $address): void
    {
        event(new UserAddressChanged((int) $address->customer_id, (int) $address->getKey()));
    }
}
