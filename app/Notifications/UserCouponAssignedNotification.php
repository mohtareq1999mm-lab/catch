<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserCouponAssignedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $assignment,
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
        $coupon = $this->assignment->coupon;

        return [
            'title' => [
                'en' => __('notifications.coupon.assigned.title', [], 'en'),
                'ar' => __('notifications.coupon.assigned.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.coupon.assigned.body', ['coupon_code' => $coupon?->code], 'en'),
                'ar' => __('notifications.coupon.assigned.body', ['coupon_code' => $coupon?->code], 'ar'),
            ],
            'icon' => 'tag',
            'resource_type' => 'coupon',
            'resource_id' => $coupon?->id,
            'action_url' => "/coupons/{$coupon?->id}",
            'coupon_assignment_id' => $this->assignment->id,
            'coupon_id' => $coupon?->id,
            'coupon_code' => $coupon?->code,
            'max_uses' => $this->assignment->max_uses ?? null,
            'expires_at' => $this->assignment->expires_at?->toIso8601String(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'coupon.assigned';
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

