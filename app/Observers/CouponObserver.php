<?php

namespace App\Observers;

use App\Events\CouponCreated;
use App\Jobs\LogActivityJob;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Coupon;

class CouponObserver
{
    public function created(Coupon $coupon): void
    {
        LogActivityJob::dispatch(
            get_class($coupon),
            $coupon->id,
            Auth::id(),
            'created',
            'coupons',
            __('activity.coupon_created'),
        );

        event(new CouponCreated($coupon));
    }

    public function updated(Coupon $coupon): void
    {
        $dirty = $coupon->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $coupon->getOriginal('status');
            $newStatus = $coupon->status;
            $description = $newStatus
                ? __('activity.coupon_enabled')
                : __('activity.coupon_disabled');
            $description = $description ?: ($newStatus ? 'Coupon enabled' : 'Coupon disabled');

            LogActivityJob::dispatch(
                get_class($coupon),
                $coupon->id,
                Auth::id(),
                'statusChanged',
                'coupons',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') continue;
                $oldValues[$key] = $coupon->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($coupon),
                $coupon->id,
                Auth::id(),
                'updated',
                'coupons',
                __('activity.coupon_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }
    }

    public function deleted(Coupon $coupon): void
    {
        LogActivityJob::dispatch(
            get_class($coupon),
            $coupon->id,
            Auth::id(),
            'deleted',
            'coupons',
            __('activity.coupon_deleted'),
        );
    }
}
