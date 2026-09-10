<?php

namespace App\Observers;

use App\Jobs\LogActivityJob;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\User;

class UserObserver
{
    public function created(User $user): void
    {
        if (!Auth::check()) {
            return;
        }

        LogActivityJob::dispatch(
            get_class($user),
            $user->id,
            Auth::id(),
            'created',
            'users',
            __('activity.user_created'),
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

            LogActivityJob::dispatch(
                get_class($user),
                $user->id,
                Auth::id(),
                'statusChanged',
                'users',
                $description,
                ['old' => ['is_active' => (string) $oldStatus], 'new' => ['is_active' => (string) $newStatus]],
            );
        }

        if ($hasOtherChanges) {
            $oldValues = [];
            $newValues = [];
            foreach ($dirty as $key => $newValue) {
                if ($key === 'is_active') continue;
                $oldValues[$key] = $user->getOriginal($key);
                $newValues[$key] = $newValue;
            }

            LogActivityJob::dispatch(
                get_class($user),
                $user->id,
                Auth::id(),
                'updated',
                'users',
                __('activity.user_updated'),
                ['old' => $oldValues, 'new' => $newValues],
            );
        }
    }

    public function deleted(User $user): void
    {
        LogActivityJob::dispatch(
            get_class($user),
            $user->id,
            Auth::id(),
            'deleted',
            'users',
            __('activity.user_deleted'),
        );
    }

    public function restored(User $user): void
    {
        LogActivityJob::dispatch(
            get_class($user),
            $user->id,
            Auth::id(),
            'restored',
            'users',
            __('activity.user_restored'),
        );
    }

    public function forceDeleted(User $user): void
    {
        LogActivityJob::dispatch(
            get_class($user),
            $user->id,
            Auth::id(),
            'forceDeleted',
            'users',
            __('activity.user_force_deleted'),
        );
    }
}
