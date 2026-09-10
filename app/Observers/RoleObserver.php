<?php

namespace App\Observers;

use App\Jobs\LogActivityJob;
use Illuminate\Support\Facades\Auth;
use Marvel\Database\Models\Role;

class RoleObserver
{
    public function created(Role $role): void
    {
        LogActivityJob::dispatch(
            get_class($role),
            $role->id,
            Auth::id(),
            'created',
            'roles',
            __('activity.role_created'),
        );
    }

    public function updated(Role $role): void
    {
        $dirty = $role->getDirty();
        unset($dirty['updated_at']);

        if (empty($dirty)) {
            return;
        }

        $oldValues = [];
        $newValues = [];
        foreach ($dirty as $key => $newValue) {
            $oldValues[$key] = $role->getOriginal($key);
            $newValues[$key] = $newValue;
        }

        LogActivityJob::dispatch(
            get_class($role),
            $role->id,
            Auth::id(),
            'updated',
            'roles',
            __('activity.role_updated'),
            ['old' => $oldValues, 'new' => $newValues],
        );
    }

    public function deleted(Role $role): void
    {
        LogActivityJob::dispatch(
            get_class($role),
            $role->id,
            Auth::id(),
            'deleted',
            'roles',
            __('activity.role_deleted'),
        );
    }
}
