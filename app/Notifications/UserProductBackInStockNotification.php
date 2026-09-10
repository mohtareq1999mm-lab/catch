<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserProductBackInStockNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $product,
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
                'en' => __('notifications.back.in_stock.title', [], 'en'),
                'ar' => __('notifications.back.in_stock.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.back.in_stock.body', ['product_name' => $this->product->name ?? ''], 'en'),
                'ar' => __('notifications.back.in_stock.body', ['product_name' => $this->product->name ?? ''], 'ar'),
            ],
            'icon' => 'box',
            'resource_type' => 'product',
            'resource_id' => $this->product->id,
            'action_url' => '/products/' . ($this->product->slug ?? $this->product->id),
            'product_id' => $this->product->id,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'back.in.stock';
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

