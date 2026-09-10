<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserOrderRefundedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $refund,
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
                'en' => __('notifications.order.refunded.title', [], 'en'),
                'ar' => __('notifications.order.refunded.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.order.refunded.body', ['order_number' => $this->refund->order_id], 'en'),
                'ar' => __('notifications.order.refunded.body', ['order_number' => $this->refund->order_id], 'ar'),
            ],
            'icon' => 'refresh',
            'resource_type' => 'refund',
            'resource_id' => $this->refund->id,
            'action_url' => "/refunds/{$this->refund->id}",
            'refund_id' => $this->refund->id,
            'order_id' => $this->refund->order_id ?? null,
            'amount' => $this->refund->amount ?? null,
            'status' => $this->refund->status ?? null,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'order.refunded';
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

