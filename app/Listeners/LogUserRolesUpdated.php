<?php

namespace App\Listeners;

use App\Events\UserRolesUpdated;
use App\Jobs\LogActivityJob;
use Illuminate\Contracts\Queue\ShouldQueue;

class LogUserRolesUpdated implements ShouldQueue
{
    public $queue = 'meem-medium';
    public function __construct()
    {
        //
    }

    public function handle(UserRolesUpdated $event): void
    {
        $oldRoles = $event->oldRoles;
        $newRoles = $event->newRoles;

        $added = array_diff($newRoles, $oldRoles);
        $removed = array_diff($oldRoles, $newRoles);

        $properties = [
            'old' => ['roles' => $oldRoles],
            'new' => ['roles' => $newRoles],
            'previous_roles' => $oldRoles,
            'new_roles' => $newRoles,
        ];

        if (!empty($added)) {
            $properties['roles_added'] = array_values($added);
        }
        if (!empty($removed)) {
            $properties['roles_removed'] = array_values($removed);
        }

        $description = __('activity.user_role_changed') ?: 'User role changed';

        LogActivityJob::dispatch(
            get_class($event->user),
            $event->user->id,
            $event->user->id,
            'roleUpdated',
            'users',
            $description,
            $properties,
        );
    }
}
