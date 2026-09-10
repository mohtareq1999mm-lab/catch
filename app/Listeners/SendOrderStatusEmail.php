<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\OrderNotification;
use App\Models\UserNotificationPreference;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;

class SendOrderStatusEmail implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'notifications';
    public $tries = 3;
    public $backoff = [60, 300, 900];

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        $user = $order->user ?? \Marvel\Database\Models\User::find($order->user_id);

        $preferences = UserNotificationPreference::forUser($user->id);

        if (!$preferences->isEventEnabled($this->getEventType($event), 'email')) {
            $this->logSkipped($order, 'Email disabled in preferences');
            return;
        }

        $email = $order->user_email ?? $user->email ?? null;
        if (!$email || (!$preferences->email_verified && $preferences->email_verified !== null && !$email)) {
            if (!$email) {
                $this->logSkipped($order, 'Email not available');
                return;
            }
            if (!$preferences->email_verified) {
                $this->logSkipped($order, 'Email not verified');
                return;
            }
        }

        $notification = OrderNotification::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'event_type' => $this->getEventType($event),
            'channel' => 'email',
            'status' => 'pending',
            'subject' => $this->getSubject($event, $preferences->notification_language ?? 'en'),
            'message' => $this->getBody($event, $preferences->notification_language ?? 'en'),
            'metadata' => [
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'tracking_url' => config('app.url') . "/track/{$order->order_number}",
            ],
        ]);

        try {
            // Attempt to send mail if Mailable exists; otherwise simulate
            if (class_exists(\App\Mail\OrderStatusChangedMail::class)) {
                Mail::to($email)->send(new \App\Mail\OrderStatusChangedMail($order, $event, $preferences->notification_language ?? 'en'));
            } else {
                // Simulate for environments without mailable
                Log::info('Email notification simulated', ['to' => $email, 'subject' => $notification->subject]);
            }

            $notification->markAsSent();
        } catch (\Throwable $e) {
            Log::error('Email listener failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
            $notification->markAsFailed($e->getMessage());
            if ($this->attempts() < $this->tries) {
                throw $e;
            }
        }
    }

    private function getEventType(OrderStatusChanged $event): string
    {
        return 'order_' . str_replace('-', '_', $event->newStatus);
    }

    private function getSubject(OrderStatusChanged $event, string $language): string
    {
        $order = $event->order;
        $subjects = [
            'en' => [
                'processing' => "Order #{$order->order_number} Confirmed",
                'completed' => "Order #{$order->order_number} Completed",
                'shipped' => "Order #{$order->order_number} Shipped",
                'out-for-delivery' => "Order #{$order->order_number} Out for Delivery",
                'out_for_delivery' => "Order #{$order->order_number} Out for Delivery",
                'delivered' => "Order #{$order->order_number} Delivered",
                'cancelled' => "Order #{$order->order_number} Cancelled",
            ],
            'ar' => [
                'processing' => "تم تأكيد الطلب #{$order->order_number}",
                'completed' => "تم إكمال الطلب #{$order->order_number}",
                'shipped' => "تم شحن الطلب #{$order->order_number}",
                'out-for-delivery' => "الطلب #{$order->order_number} في طريقه للتسليم",
                'out_for_delivery' => "الطلب #{$order->order_number} في طريقه للتسليم",
                'delivered' => "تم تسليم الطلب #{$order->order_number}",
                'cancelled' => "تم إلغاء الطلب #{$order->order_number}",
            ],
        ];
        $status = $event->newStatus;
        return $subjects[$language][$status] ?? $subjects['en'][$status] ?? "Order {$order->order_number} Update";
    }

    private function getBody(OrderStatusChanged $event, string $language): string
    {
        $order = $event->order;
        $bodies = [
            'en' => [
                'processing' => "Your order #{$order->order_number} is being processed.",
                'completed' => "Your order #{$order->order_number} has been completed.",
                'shipped' => "Your order #{$order->order_number} has been shipped.",
                'delivered' => "Your order #{$order->order_number} has been delivered.",
                'cancelled' => "Your order #{$order->order_number} has been cancelled.",
            ],
            'ar' => [
                'processing' => "طلبك #{$order->order_number} قيد المعالجة.",
                'completed' => "تم إكمال طلبك #{$order->order_number}.",
                'shipped' => "تم شحن طلبك #{$order->order_number}.",
                'delivered' => "تم تسليم طلبك #{$order->order_number}.",
                'cancelled' => "تم إلغاء طلبك #{$order->order_number}.",
            ],
        ];
        return $bodies[$language][$event->newStatus] ?? $bodies['en'][$event->newStatus] ?? "Status: {$event->newStatus}";
    }

    private function logSkipped($order, string $reason): void
    {
        try {
            OrderNotification::create([
                'order_id' => $order->id,
                'user_id' => $order->user_id,
                'event_type' => 'order_status_changed',
                'channel' => 'email',
                'status' => 'skipped',
                'failure_reason' => $reason,
            ]);
        } catch (\Throwable $e) {}
    }
}
