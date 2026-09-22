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
        $this->onQueue(config('queue.queues.high'));
    }

    public function via($notifiable): array
    {
        // FINAL BUSINESS CONTRACT Sec 6: user must receive in-app (database),
        // realtime (broadcast/Pusher), push (fcm/Firebase) AND email (mail).
        // Mail is conditional: phone-based accounts may have no email address.
        $channels = ['database', 'fcm', 'broadcast'];

        $email = is_string($notifiable->email ?? null) ? trim($notifiable->email) : '';
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $channels[] = 'mail';
        }

        return $channels;
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
        return (new BroadcastMessage($this->toDatabase($notifiable)))->onQueue(config('queue.queues.high'));
    }

    public function toMail($notifiable): \Illuminate\Notifications\Messages\MailMessage
    {
        // Marvel User has no preferredLocale(); fall back to app locale like
        // toDatabase() does (explicit en/ar payloads there).
        $locale = app()->getLocale();
        $data = $this->toDatabase($notifiable);
        $code = $data['coupon_code'] ?? '';

        return (new \Illuminate\Notifications\Messages\MailMessage)
            ->subject(__('notifications.coupon.assigned.title', [], $locale))
            ->line(__('notifications.coupon.assigned.body', ['coupon_code' => $code], $locale))
            ->action(__('notifications.coupon.assigned.title', [], $locale), url($data['action_url'] ?? '/coupons'));
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

