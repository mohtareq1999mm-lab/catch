<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserReviewApprovedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $review,
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
                'en' => __('notifications.review.approved.title', [], 'en'),
                'ar' => __('notifications.review.approved.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.review.approved.body', ['product_name' => $this->review->product?->name ?? ''], 'en'),
                'ar' => __('notifications.review.approved.body', ['product_name' => $this->review->product?->name ?? ''], 'ar'),
            ],
            'icon' => 'star',
            'resource_type' => 'review',
            'resource_id' => $this->review->id,
            'action_url' => '/reviews/' . $this->review->id,
            'review_id' => $this->review->id,
            'product_id' => $this->review->product_id,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'review.approved';
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

