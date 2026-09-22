<?php

namespace App\Listeners;

use App\Events\PaymentFailed;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordPaymentFailedInTimeline
{
    public function handle(PaymentFailed $event): void
    {
        try {
            $order = $event->order;
            $failedAttempts = OrderTrackingEvent::where('order_id', $order->id)
                ->where('event_type', 'payment.failed')
                ->count();

            OrderTrackingEvent::create([
                'order_id' => $order->id,
                'event_type' => 'payment.failed',
                'event_timestamp' => now(),
                'actor_type' => 'customer',
                'actor_id' => $order->user_id,
                'actor_name' => $order->user?->name ?? 'Customer',
                'old_status' => null,
                'new_status' => null,
                'metadata' => [
                    'order_number' => $order->order_number,
                    'failed_at' => now()->toIso8601String(),
                    'retry_attempt' => $failedAttempts + 1,
                ],
                'customer_visible' => true,
                'customer_label_key' => 'tracking.payment.failed',
                'customer_description_key' => 'tracking.payment.failed_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Payment failed tracking event recorded', [
                'order_id' => $order->id,
                'retry_attempt' => $failedAttempts + 1,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record payment failed tracking event', [
                'order_id' => $event->order->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
