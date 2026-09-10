<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class NewOrderNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $order,
    ) {
        $this->onQueue('meem-medium');
    }

    public function via(): array
    {
        return ['database',
            'fcm', 'broadcast'];
    }

    public function toDatabase(): array
    {
        return [
            'title' => [
                'en' => __('notifications.admin.new_order.title', [], 'en'),
                'ar' => __('notifications.admin.new_order.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.admin.new_order.body', ['order_number' => $this->order->order_number], 'en'),
                'ar' => __('notifications.admin.new_order.body', ['order_number' => $this->order->order_number], 'ar'),
            ],
            'icon' => 'shopping-cart',
            'resource_type' => 'order',
            'resource_id' => $this->order->id,
            'action_url' => "/admin/orders/{$this->order->id}",
            'order_id' => $this->order->id,
            'order_number' => $this->order->order_number,
            'customer_name' => $this->order->name ?? $this->order->user?->name,
            'total_amount' => $this->order->total_price ?? $this->order->price,
            'payment_status' => $this->order->payment_status ?? 'pending',
            'order_status' => $this->order->status ?? 'pending',
        ];
    }

    public function toBroadcast($notifiable)
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'order.created';
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