<?php

namespace App\Observers;

use App\Jobs\LogActivityJob;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Brand;

class BrandObserver
{
    public function created(Brand $brand): void
    {
        LogActivityJob::dispatch(
            get_class($brand),
            $brand->id,
            Auth::id(),
            'created',
            'brands',
            __('activity.brand_created'),
        );
    }

    public function updated(Brand $brand): void
    {
        $dirty = $brand->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $brand->getOriginal('status');
            $newStatus = $brand->status;
            $description = $newStatus
                ? __('activity.brand_activated')
                : __('activity.brand_deactivated');
            $description = $description ?: ($newStatus ? 'Brand activated' : 'Brand deactivated');

            LogActivityJob::dispatch(
                get_class($brand),
                $brand->id,
                Auth::id(),
                'statusChanged',
                'brands',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') continue;
                $oldValues[$key] = $brand->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($brand),
                $brand->id,
                Auth::id(),
                'updated',
                'brands',
                __('activity.brand_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }
    }

    public function deleted(Brand $brand): void
    {
        LogActivityJob::dispatch(
            get_class($brand),
            $brand->id,
            Auth::id(),
            'deleted',
            'brands',
            __('activity.brand_deleted'),
        );
    }
}
