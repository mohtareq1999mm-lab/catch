<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserPromotionEndingSoonNotification extends Notification implements ShouldQueue
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
                'en' => __('notifications.promotion.ending_soon.title', [], 'en'),
                'ar' => __('notifications.promotion.ending_soon.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.promotion.ending_soon.body', ['promotion_name' => $this->promotion->name ?? ''], 'en'),
                'ar' => __('notifications.promotion.ending_soon.body', ['promotion_name' => $this->promotion->name ?? ''], 'ar'),
            ],
            'icon' => 'hourglass',
            'resource_type' => 'promotion',
            'resource_id' => $this->promotion->id,
            'action_url' => '/promotions/' . $this->promotion->id,
            'promotion_id' => $this->promotion->id,
            'end_at' => $this->promotion->end_at?->toIso8601String(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'promotion.ending_soon';
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

