<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserProductPriceDropNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $product,
        public $oldPrice = null,
        public $newPrice = null,
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
                'en' => __('notifications.price.drop.title', [], 'en'),
                'ar' => __('notifications.price.drop.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.price.drop.body', [
                    'product_name' => $this->product->name ?? '',
                    'old_price' => $this->oldPrice ?? '',
                    'new_price' => $this->newPrice ?? '',
                ], 'en'),
                'ar' => __('notifications.price.drop.body', [
                    'product_name' => $this->product->name ?? '',
                    'old_price' => $this->oldPrice ?? '',
                    'new_price' => $this->newPrice ?? '',
                ], 'ar'),
            ],
            'icon' => 'price-drop',
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
        return 'price.drop';
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

