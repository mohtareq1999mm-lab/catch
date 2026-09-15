<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use App\Events\PromotionActivated;
use Marvel\Database\Models\Promotion;

class PromotionObserver
{
    private const TRACKED_FIELDS = [
        'name', 'slug', 'type', 'type_amount', 'value', 'discount',
        'max_discount_amount', 'minimum_order_amount', 'apply_to',
        'required_quantity_type', 'limiter', 'usage', 'start_at', 'end_at',
        'status',
    ];

    public function created(Promotion $promotion): void
    {
        ActivityAuditService::recordModel(
            $promotion,
            'created',
            'promotions',
            __('activity.promotion_created'),
            new: $promotion->getAttributes(),
        );

        if ($promotion->status === true) {
            event(new PromotionActivated($promotion));
        }
    }

    public function updated(Promotion $promotion): void
    {
        $dirty = $promotion->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count(array_intersect_key($dirty, array_flip(self::TRACKED_FIELDS))) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $promotion->getOriginal('status');
            $newStatus = $promotion->status;
            $description = $newStatus
                ? __('activity.promotion_activated')
                : __('activity.promotion_deactivated');
            $description = $description ?: ($newStatus ? 'Promotion activated' : 'Promotion deactivated');

            ActivityAuditService::recordModel(
                $promotion,
                'statusChanged',
                'promotions',
                $description,
                old: ['status' => $oldStatus],
                new: ['status' => $newStatus],
            );

            if ($oldStatus == false && $newStatus == true) {
                event(new PromotionActivated($promotion));
            }
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if (!in_array($key, self::TRACKED_FIELDS) || $key === 'status') {
                    continue;
                }
                $oldValues[$key] = $promotion->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            if (!empty($oldValues)) {
                ActivityAuditService::recordModel(
                    $promotion,
                    'updated',
                    'promotions',
                    __('activity.promotion_updated'),
                    old: $oldValues,
                    new: $newValues,
                );
            }
        }
    }

    public function deleted(Promotion $promotion): void
    {
        ActivityAuditService::recordModel(
            $promotion,
            'deleted',
            'promotions',
            __('activity.promotion_deleted'),
            old: $promotion->getAttributes(),
        );
    }
}
