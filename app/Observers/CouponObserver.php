<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use App\Events\CouponCreated;
use Marvel\Database\Models\Coupon;

class CouponObserver
{
    public function created(Coupon $coupon): void
    {
        ActivityAuditService::recordModel(
            $coupon,
            'created',
            'coupons',
            __('activity.coupon_created'),
            new: $coupon->getAttributes(),
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

            ActivityAuditService::recordModel(
                $coupon,
                'statusChanged',
                'coupons',
                $description,
                old: ['status' => $oldStatus],
                new: ['status' => $newStatus],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') {
                    continue;
                }
                $oldValues[$key] = $coupon->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            ActivityAuditService::recordModel(
                $coupon,
                'updated',
                'coupons',
                __('activity.coupon_updated'),
                old: $oldValues,
                new: $newValues,
            );
        }
    }

    public function deleted(Coupon $coupon): void
    {
        ActivityAuditService::recordModel(
            $coupon,
            'deleted',
            'coupons',
            __('activity.coupon_deleted'),
            old: $coupon->getAttributes(),
        );
    }
}
