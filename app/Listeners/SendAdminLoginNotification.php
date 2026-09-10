<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\AdminLoggedIn;
use App\Notifications\AdminLoggedInNotification;
use Illuminate\Support\Facades\Notification;

class SendAdminLoginNotification
{
    public function handle(AdminLoggedIn $event): void
    {
        $admins = $this->getAdminUsers();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new AdminLoggedInNotification(
                $event->admin,
                $event->ip,
                $event->userAgent,
            ));
        }
    }

    private function getAdminUsers()
    {
        $adminModel = config('auth.providers.users.model');

        return $adminModel::where('type', UserType::ADMIN->value)
            ->where('is_active', true)
            ->get();
    }
}
