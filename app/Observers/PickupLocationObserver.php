<?php

namespace App\Observers;

use App\Jobs\LogActivityJob;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\PickupLocation;

class PickupLocationObserver
{
    public function created(PickupLocation $pickupLocation): void
    {
        LogActivityJob::dispatch(
            get_class($pickupLocation),
            $pickupLocation->id,
            Auth::id(),
            'created',
            'pickup_locations',
            __('activity.pickup_location_created'),
        );
    }

    public function updated(PickupLocation $pickupLocation): void
    {
        $dirty = $pickupLocation->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $statusChanged = array_key_exists('status', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $pickupLocation->getOriginal('status');
            $newStatus = $pickupLocation->status;
            $description = $newStatus
                ? __('activity.pickup_location_activated')
                : __('activity.pickup_location_deactivated');

            LogActivityJob::dispatch(
                get_class($pickupLocation),
                $pickupLocation->id,
                Auth::id(),
                'statusChanged',
                'pickup_locations',
                $description,
                ['old' => ['status' => (string) $oldStatus], 'new' => ['status' => (string) $newStatus]],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'status') continue;
                $oldValues[$key] = $pickupLocation->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($pickupLocation),
                $pickupLocation->id,
                Auth::id(),
                'updated',
                'pickup_locations',
                __('activity.pickup_location_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }
    }

    public function deleted(PickupLocation $pickupLocation): void
    {
        LogActivityJob::dispatch(
            get_class($pickupLocation),
            $pickupLocation->id,
            Auth::id(),
            'deleted',
            'pickup_locations',
            __('activity.pickup_location_deleted'),
        );
    }
}
