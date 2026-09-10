<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserPromotionAvailableNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $promotion,
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
                'en' => __('notifications.promotion.available.title', [], 'en'),
                'ar' => __('notifications.promotion.available.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.promotion.available.body', ['promotion_name' => $this->promotion->name], 'en'),
                'ar' => __('notifications.promotion.available.body', ['promotion_name' => $this->promotion->name], 'ar'),
            ],
            'icon' => 'tag',
            'resource_type' => 'promotion',
            'resource_id' => $this->promotion->id,
            'action_url' => "/promotions/{$this->promotion->id}",
            'promotion_id' => $this->promotion->id,
            'promotion_code' => $this->promotion->code,
            'discount_type' => $this->promotion->type_amount,
            'discount_value' => $this->promotion->discount ?? $this->promotion->value,
            'start_at' => $this->promotion->start_at?->toIso8601String(),
            'end_at' => $this->promotion->end_at?->toIso8601String(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'promotion.available';
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

