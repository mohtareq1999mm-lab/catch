<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserDigitalProductsAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $order,
        public $entitlements = null,
    ) {
        $this->onQueue('meem-medium');
    }

    public function via($notifiable): array
    {
        return ['database', 'fcm', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        return [
            'title' => [
                'en' => __('notifications.digital.available.title', [], 'en'),
                'ar' => __('notifications.digital.available.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.digital.available.body', ['order_number' => $this->order->order_number], 'en'),
                'ar' => __('notifications.digital.available.body', ['order_number' => $this->order->order_number], 'ar'),
            ],
            'icon' => 'download',
            'resource_type' => 'order',
            'resource_id' => $this->order->id,
            'action_url' => "/orders/{$this->order->id}",
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'digital.products_available';
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
