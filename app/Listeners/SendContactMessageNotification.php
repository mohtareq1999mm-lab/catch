<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\ContactMessageReceived;
use App\Notifications\NewContactMessageNotification;
use Illuminate\Support\Facades\Notification;

class SendContactMessageNotification
{
    public function handle(ContactMessageReceived $event): void
    {
        $admins = $this->getAdminUsers();

        if ($admins->isNotEmpty()) {
            Notification::send($admins, new NewContactMessageNotification($event->contact));
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
