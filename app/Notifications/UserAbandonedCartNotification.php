<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserAbandonedCartNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $cart,
    ) {
        $this->onQueue('meem-medium');
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
                'en' => __('notifications.cart.abandoned.title', [], 'en'),
                'ar' => __('notifications.cart.abandoned.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.cart.abandoned.body', [], 'en'),
                'ar' => __('notifications.cart.abandoned.body', [], 'ar'),
            ],
            'icon' => 'cart',
            'resource_type' => 'cart',
            'resource_id' => $this->cart->id,
            'action_url' => '/cart',
            'cart_id' => $this->cart->id,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'cart.abandoned';
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

