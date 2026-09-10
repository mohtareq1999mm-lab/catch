<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class AdminDigitalDeliveryFailedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $order,
        public string $reason = '',
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
                'en' => __('notifications.admin.digital_delivery_failed.title', [], 'en'),
                'ar' => __('notifications.admin.digital_delivery_failed.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.admin.digital_delivery_failed.body', [
                    'order_number' => $this->order->order_number,
                    'reason' => $this->reason,
                ], 'en'),
                'ar' => __('notifications.admin.digital_delivery_failed.body', [
                    'order_number' => $this->order->order_number,
                    'reason' => $this->reason,
                ], 'ar'),
            ],
            'icon' => 'alert-triangle',
            'resource_type' => 'order',
            'resource_id' => $this->order->id,
            'action_url' => "/admin/orders/{$this->order->id}",
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
        return 'digital.delivery_failed';
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
