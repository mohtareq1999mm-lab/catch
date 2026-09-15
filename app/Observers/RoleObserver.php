<?php

namespace App\Observers;

use App\Audit\ActivityAuditService;
use Marvel\Database\Models\Role;

class RoleObserver
{
    public function created(Role $role): void
    {
        ActivityAuditService::recordModel(
            $role,
            'created',
            'roles',
            __('activity.role_created'),
            new: $role->getAttributes(),
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

        ActivityAuditService::recordModel(
            $role,
            'updated',
            'roles',
            __('activity.role_updated'),
            old: $oldValues,
            new: $newValues,
        );
    }

    public function deleted(Role $role): void
    {
        ActivityAuditService::recordModel(
            $role,
            'deleted',
            'roles',
            __('activity.role_deleted'),
            old: $role->getAttributes(),
        );
    }
}
