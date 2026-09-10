<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class UserCouponUsedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $coupon,
        public $couponAssignment,
        public $user,
        public $order,
        public int $remainingUses,
        public $consumedAt,
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
                'en' => __('notifications.coupon.used.title', [], 'en'),
                'ar' => __('notifications.coupon.used.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.coupon.used.body', ['coupon_code' => $this->coupon?->code], 'en'),
                'ar' => __('notifications.coupon.used.body', ['coupon_code' => $this->coupon?->code], 'ar'),
            ],
            'icon' => 'tag',
            'resource_type' => 'coupon',
            'resource_id' => $this->coupon?->id,
            'action_url' => "/coupons/{$this->coupon?->id}",
            'coupon_id' => $this->coupon?->id,
            'coupon_code' => $this->coupon?->code,
            'order_id' => $this->order?->id,
            'remaining_uses' => $this->remainingUses,
            'consumed_at' => $this->consumedAt?->toIso8601String(),
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue('meem-medium');
    }

    public function broadcastType(): string
    {
        return 'coupon.used';
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