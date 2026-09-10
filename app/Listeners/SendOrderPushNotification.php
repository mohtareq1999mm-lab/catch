<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\OrderNotification;
use App\Models\UserNotificationPreference;
use App\Services\Notifications\PushNotificationService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;

class SendOrderPushNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'notifications';
    public $tries = 2;

    public function __construct(private PushNotificationService $pushService) {}

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        $user = $order->user ?? \Marvel\Database\Models\User::find($order->user_id);

        $preferences = UserNotificationPreference::forUser($user->id);

        if (!$preferences->isEventEnabled($this->getEventType($event), 'push')) {
            $this->logSkipped($order, 'Push disabled in preferences');
            return;
        }

        $title = $this->getTitle($event, $preferences->notification_language ?? 'en');
        $body = $this->getBody($event, $preferences->notification_language ?? 'en');
        $data = [
            'type' => 'order_status_changed',
            'order_id' => (string) $order->id,
            'order_number' => $order->order_number,
            'status' => $event->newStatus,
            'click_action' => 'ORDER_DETAIL',
        ];

        $notification = OrderNotification::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'event_type' => $this->getEventType($event),
            'channel' => 'push',
            'status' => 'pending',
            'subject' => $title,
            'message' => $body,
            'metadata' => $data,
        ]);

        try {
            $result = $this->pushService->sendToUser($user->id, $title, $body, $data);

            if (($result['sent'] ?? 0) > 0) {
                $notification->markAsSent(null, $result);
            } else {
                $notification->markAsSkipped('No active devices');
            }
        } catch (\Throwable $e) {
            Log::error('Push listener failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
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

    private function getTitle(OrderStatusChanged $event, string $language): string
    {
        $titles = [
            'en' => [
                'processing' => 'Order Confirmed',
                'completed' => 'Order Completed',
                'shipped' => 'Order Shipped',
                'out_for_delivery' => 'Out for Delivery',
                'out-for-delivery' => 'Out for Delivery',
                'delivered' => 'Order Delivered',
                'cancelled' => 'Order Cancelled',
            ],
            'ar' => [
                'processing' => 'تم تأكيد الطلب',
                'completed' => 'تم إكمال الطلب',
                'shipped' => 'تم شحن الطلب',
                'delivered' => 'تم تسليم الطلب',
                'cancelled' => 'تم إلغاء الطلب',
            ],
        ];
        return $titles[$language][$event->newStatus] ?? $titles['en'][$event->newStatus] ?? 'Order Update';
    }

    private function getBody(OrderStatusChanged $event, string $language): string
    {
        $order = $event->order;
        $bodies = [
            'en' => [
                'processing' => "Your order #{$order->order_number} is being processed",
                'completed' => "Your order #{$order->order_number} has been completed",
                'shipped' => "Your order #{$order->order_number} has been shipped",
                'delivered' => "Your order #{$order->order_number} has been delivered",
                'cancelled' => "Your order #{$order->order_number} has been cancelled",
            ],
            'ar' => [
                'processing' => "طلبك #{$order->order_number} قيد المعالجة",
                'completed' => "تم إكمال طلبك #{$order->order_number}",
                'shipped' => "تم شحن طلبك #{$order->order_number}",
                'delivered' => "تم تسليم طلبك #{$order->order_number}",
                'cancelled' => "تم إلغاء طلبك #{$order->order_number}",
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
                'channel' => 'push',
                'status' => 'skipped',
                'failure_reason' => $reason,
            ]);
        } catch (\Throwable $e) {}
    }
}
