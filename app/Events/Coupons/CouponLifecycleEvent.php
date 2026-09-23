<?php

namespace App\Events\Coupons;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Marvel\Database\Models\Coupon;

/**
 * Thin lifecycle signals for the coupon distribution plane.
 * All implement ShouldDispatchAfterCommit: listeners boot queued work that
 * must observe committed state. No business logic lives here.
 */
abstract class CouponLifecycleEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly Coupon $coupon,
    ) {}
}
