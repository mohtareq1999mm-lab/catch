<?php

namespace App\Events\Coupons;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired when a user creates, changes, or deletes a saved address.
 * Governed by area_in relevance: trees without area_in never fan out.
 */
class UserAddressChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $userId,
        public readonly ?int $addressId = null,
    ) {}
}
