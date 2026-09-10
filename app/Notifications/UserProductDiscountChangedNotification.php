<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserProductDiscountChangedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $product,
        public array $oldValues = [],
        public array $newValues = [],
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
                'en' => __('notifications.discount.changed.title', [], 'en'),
                'ar' => __('notifications.discount.changed.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.discount.changed.body', ['product_name' => $this->product->name ?? ''], 'en'),
                'ar' => __('notifications.discount.changed.body', ['product_name' => $this->product->name ?? ''], 'ar'),
            ],
            'icon' => 'tag',
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
        return 'discount.changed';
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

