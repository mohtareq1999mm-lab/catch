<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserPaymentSucceededNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $order,
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
                'en' => __('notifications.payment.succeeded.title', [], 'en'),
                'ar' => __('notifications.payment.succeeded.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.payment.succeeded.body', ['order_number' => $this->order->order_number], 'en'),
                'ar' => __('notifications.payment.succeeded.body', ['order_number' => $this->order->order_number], 'ar'),
            ],
            'icon' => 'credit-card',
            'resource_type' => 'order',
            'resource_id' => $this->order->id,
            'action_url' => "/orders/{$this->order->id}",
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'total_amount' => $this->order->total_price ?? $this->order->price,
            'payment_status' => 'succeeded',
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'payment.succeeded';
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

