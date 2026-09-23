<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use App\Services\Coupon\CouponClaimRequirement;

/**
 * Per-user dynamic-eligibility notification (type coupon.eligible).
 *
 * Channels mirror the assignment notification: database (durable truth) +
 * fcm + broadcast. Database write is independent — FCM/Pusher failures
 * never delete it.
 *
 * CONFIDENTIALITY: never carries coupon_code, rules, metrics, counters,
 * or other-user data. The customer claims first; the code appears only
 * under /mine (owner-scoped) after a successful claim.
 */
class UserCouponEligibleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public $coupon,
        public readonly ?int $distributionRunId = null,
        public readonly ?string $treeHash = null,
    ) {
        $this->onQueue(config('queue.queues.high'));
    }

    public function via($notifiable): array
    {
        return ['database', 'fcm', 'broadcast'];
    }

    public function toDatabase($notifiable): array
    {
        $coupon = $this->coupon;

        return [
            'title' => [
                'en' => __('notifications.coupon.eligible.title', [], 'en'),
                'ar' => __('notifications.coupon.eligible.title', [], 'ar'),
            ],
            'message' => [
                'en' => __('notifications.coupon.eligible.body', ['coupon_name' => $coupon?->name], 'en'),
                'ar' => __('notifications.coupon.eligible.body', ['coupon_name' => $coupon?->name], 'ar'),
            ],
            'icon' => 'tag',
            'resource_type' => 'coupon',
            'resource_id' => $coupon?->id,
            'action_url' => "/coupons/{$coupon?->id}",
            'coupon_id' => $coupon?->id,
            'requires_claim' => CouponClaimRequirement::forCoupon($coupon),
            'tree_hash' => $this->treeHash,
            'run_id' => $this->distributionRunId,
        ];
    }

    public function toBroadcast($notifiable): BroadcastMessage
    {
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue(config('queue.queues.high'));
    }

    public function broadcastType(): string
    {
        return 'coupon.eligible';
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
