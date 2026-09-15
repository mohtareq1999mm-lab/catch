<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use Marvel\Database\Models\PickupLocation;

class PickupLocationObserver
{
    public function created(PickupLocation $pickupLocation): void
    {
        ActivityAuditService::recordModel(
            $pickupLocation,
            'created',
            'pickup_locations',
            __('activity.pickup_location_created'),
            new: $pickupLocation->getAttributes(),
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

            ActivityAuditService::recordModel(
                $pickupLocation,
                'statusChanged',
                'pickup_locations',
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
                $oldValues[$key] = $pickupLocation->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            ActivityAuditService::recordModel(
                $pickupLocation,
                'updated',
                'pickup_locations',
                __('activity.pickup_location_updated'),
                old: $oldValues,
                new: $newValues,
            );
        }
    }

    public function deleted(PickupLocation $pickupLocation): void
    {
        ActivityAuditService::recordModel(
            $pickupLocation,
            'deleted',
            'pickup_locations',
            __('activity.pickup_location_deleted'),
            old: $pickupLocation->getAttributes(),
        );
    }

    public function restored(PickupLocation $pickupLocation): void
    {
        ActivityAuditService::recordModel(
            $pickupLocation,
            'restored',
            'pickup_locations',
            'Pickup location restored',
            new: $pickupLocation->getAttributes(),
        );
    }

    public function forceDeleted(PickupLocation $pickupLocation): void
    {
        ActivityAuditService::recordModel(
            $pickupLocation,
            'forceDeleted',
            'pickup_locations',
            'Pickup location permanently deleted',
            old: $pickupLocation->getAttributes(),
        );
    }
}
