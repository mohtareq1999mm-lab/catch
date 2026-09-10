<?php

namespace App\Observers;

use App\Events\PromotionActivated;
use App\Jobs\LogActivityJob;
use Illuminate\Support\Facades\Auth;
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
        LogActivityJob::dispatch(
            get_class($promotion),
            $promotion->id,
            Auth::id(),
            'created',
            'promotions',
            __('activity.promotion_created'),
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

            LogActivityJob::dispatch(
                get_class($promotion),
                $promotion->id,
                Auth::id(),
                'statusChanged',
                'promotions',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );

            if ($oldStatus == false && $newStatus == true) {
                event(new PromotionActivated($promotion));
            }
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if (!in_array($key, self::TRACKED_FIELDS) || $key === 'status') continue;
                $oldValues[$key] = $promotion->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            if (!empty($oldValues)) {
                LogActivityJob::dispatch(
                    get_class($promotion),
                    $promotion->id,
                    Auth::id(),
                    'updated',
                    'promotions',
                    __('activity.promotion_updated'),
                    ['old' => $oldValues, 'new' => $newValues],
                );
            }
        }
    }

    public function deleted(Promotion $promotion): void
    {
        LogActivityJob::dispatch(
            get_class($promotion),
            $promotion->id,
            Auth::id(),
            'deleted',
            'promotions',
            __('activity.promotion_deleted'),
        );
    }
}
