<?php

namespace App\Services\Coupon\Distribution;

use Carbon\Carbon;

/**
 * Single fan-out liveness rule: status + dates only. Claim capacity
 * (max_claims/limiter/reservation/usage) is NEVER a fan-out gate — those
 * stay authoritative at claim/reservation/usage stages.
 */
final class CouponLiveCheck
{
    public static function isLive(\Marvel\Database\Models\Coupon $coupon, ?Carbon $now = null): bool
    {
        $today = $now ?? today();

        return (bool) $coupon->status
            && (! $coupon->start_date || $coupon->start_date->lte($today))
            && (! $coupon->end_date || $coupon->end_date->gte($today));
    }
}
