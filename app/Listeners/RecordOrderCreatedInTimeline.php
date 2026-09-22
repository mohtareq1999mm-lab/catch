<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordOrderCreatedInTimeline
{
    /**
     * Handle the event.
     */
    public function handle(OrderCreated $event): void
    {
        try {
            OrderTrackingEvent::create([
                'order_id' => $event->order->id,
                'event_type' => 'order.created',
                'event_timestamp' => $event->order->created_at ?? now(),
                'actor_type' => 'customer',
                'actor_id' => $event->order->customer_id,
                'actor_name' => $event->order->customer?->name ?? 'Customer',
                'old_status' => null,
                'new_status' => $event->order->order_status,
                'metadata' => [
                    'order_number' => $event->order->tracking_number,
                    'total_amount' => $event->order->paid_total,
                    'currency' => $event->order->currency ?? 'SAR',
                    'items_count' => $event->order->products?->count() ?? 0,
                ],
                'customer_visible' => true,
                'customer_label_key' => 'tracking.order.created',
                'customer_description_key' => 'tracking.order.created_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Order tracking event recorded', [
                'order_id' => $event->order->id,
                'event_type' => 'order.created',
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record order tracking event', [
                'order_id' => $event->order->id,
                'event_type' => 'order.created',
                'error' => $e->getMessage(),
            ]);
            
            // Don't throw - tracking is non-critical
        }
    }
}
