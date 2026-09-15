<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use Marvel\Database\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        ActivityAuditService::recordModel(
            $user,
            'created',
            'users',
            __('activity.user_created'),
            new: $user->getAttributes(),
        );
    }

    public function updated(User $user): void
    {
        $dirty = $user->getDirty();
        unset($dirty['updated_at'], $dirty['remember_token']);

        if (empty($dirty)) {
            return;
        }

        $statusChanged = array_key_exists('is_active', $dirty);
        $hasOtherChanges = count($dirty) > ($statusChanged ? 1 : 0);

        if ($statusChanged) {
            $oldStatus = $user->getOriginal('is_active');
            $newStatus = $user->is_active;
            $description = $newStatus
                ? __('activity.user_activated')
                : __('activity.user_deactivated');
            $description = $description ?: ($newStatus ? 'User activated' : 'User deactivated');

            ActivityAuditService::recordModel(
                $user,
                'statusChanged',
                'users',
                $description,
                old: ['is_active' => $oldStatus],
                new: ['is_active' => $newStatus],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'is_active') {
                    continue;
                }
                $oldValues[$key] = $user->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            ActivityAuditService::recordModel(
                $user,
                'updated',
                'users',
                __('activity.user_updated'),
                old: $oldValues,
                new: $newValues,
            );
        }
    }

    public function deleted(User $user): void
    {
        ActivityAuditService::recordModel(
            $user,
            'deleted',
            'users',
            __('activity.user_deleted'),
            old: $user->getAttributes(),
        );
    }

    public function restored(User $user): void
    {
        ActivityAuditService::recordModel(
            $user,
            'restored',
            'users',
            __('activity.user_restored'),
            new: $user->getAttributes(),
        );
    }

    public function forceDeleted(User $user): void
    {
        ActivityAuditService::recordModel(
            $user,
            'forceDeleted',
            'users',
            __('activity.user_force_deleted'),
            old: $user->getAttributes(),
        );
    }
}
