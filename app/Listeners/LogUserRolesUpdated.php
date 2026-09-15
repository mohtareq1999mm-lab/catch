<?php

namespace App\Listeners;

use App\Audit\ActivityAuditService;
use App\Events\UserRolesUpdated;
use Illuminate\Contracts\Queue\ShouldQueue;

class LogUserRolesUpdated implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

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

        $description = __('activity.user_role_changed') ?: 'User role changed';

        ActivityAuditService::recordSubject(
            get_class($event->user),
            (int) $event->user->id,
            'roleUpdated',
            'users',
            $description,
            old: ['roles' => $oldRoles],
            new: ['roles' => $newRoles],
            context: [
                'source' => 'queue',
                'job' => self::class,
                'roles_added' => array_values($added),
                'roles_removed' => array_values($removed),
            ],
            causerId: (int) $event->user->id,
            causerType: get_class($event->user),
        );
    }
}
