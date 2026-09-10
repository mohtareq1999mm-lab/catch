<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AdminLoggedInNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $admin,
        public string $ip,
        public string $userAgent,
    ) {
        $this->onQueue('meem-high');
    }

    public function via($notifiable): array
    {
        return ['database',
            'fcm', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => [
                'en' => __('notifications.admin.login.title', [], 'en'),
                'ar' => __('notifications.admin.login.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.admin.login.body', ['admin_name' => $this->admin->name], 'en'),
                'ar' => __('notifications.admin.login.body', ['admin_name' => $this->admin->name], 'ar'),
            ],
            'icon' => 'log-in',
            'resource_type' => 'admin',
            'resource_id' => $this->admin->id,
            'action_url' => '/admin/admins',
            'admin_id' => $this->admin->id,
            'admin_name' => $this->admin->name,
            'admin_email' => $this->admin->email,
            'login_time' => now()->toIso8601String(),
            'login_ip' => $this->ip,
            'user_agent' => $this->userAgent,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'admin.login';
    }

    public function broadcastAs(): string
    {
        return $this->broadcastType();
    }

    public function databaseType($notifiable): string
    {
        return $this->broadcastType();
    }
}

