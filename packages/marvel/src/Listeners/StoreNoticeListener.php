<?php

namespace Marvel\Listeners;


use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Marvel\Enums\Role;
use Marvel\Events\StoreNoticeEvent;
use Marvel\Notifications\StoreNoticeNotification;

class StoreNoticeListener implements ShouldQueue
{
    public $queue = 'meem-medium';
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Handle the event.
     *
     * @param StoreNoticeEvent $event
     * @return void
     */
    public function handle(StoreNoticeEvent $event)
    {
        $users = User::whereHas('roles', function (Builder $query) {
            $query->whereIn('name', [Role::SUPER_ADMIN]);
        })->get();

        if (!empty($users)) {
            foreach ($users as $user) {
                $user->notify(new StoreNoticeNotification($event->storeNotice, $event->action));
            }
        }
    }
}
