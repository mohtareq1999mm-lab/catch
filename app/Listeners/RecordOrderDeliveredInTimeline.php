<?php

namespace App\Listeners;

use App\Events\OrderDelivered;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordOrderDeliveredInTimeline
{
    public function handle(OrderDelivered $event): void
    {
        try {
            OrderTrackingEvent::create([
                'order_id' => $event->order->id,
                'event_type' => 'fulfillment.delivered',
                'event_timestamp' => now(),
                'actor_type' => 'courier',
                'actor_id' => null,
                'actor_name' => 'Courier',
                'old_status' => null,
                'new_status' => null,
                'metadata' => [
                    'order_number' => $event->order->order_number,
                    'delivered_at' => now()->toIso8601String(),
                ],
                'customer_visible' => true,
                'customer_label_key' => 'tracking.fulfillment.delivered',
                'customer_description_key' => 'tracking.fulfillment.delivered_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Order delivered tracking event recorded', [
                'order_id' => $event->order->id,
                'delivered_at' => now()->toIso8601String(),
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record order delivered tracking event', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
