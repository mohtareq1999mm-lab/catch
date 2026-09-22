<?php

namespace App\Listeners;

use App\Events\OrderStatusChanged;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordOrderStatusChangeInTimeline
{

    /**
     * Handle the event.
     */
    public function handle(OrderStatusChanged $event): void
    {
        try {
            $isCancellation = $event->newStatus === 'cancelled';
            $metadata = [
                'order_number' => $event->order->order_number,
                'previous_status' => $event->oldStatus,
                'current_status' => $event->newStatus,
                'changed_at' => now()->toIso8601String(),
            ];

            if ($isCancellation) {
                $metadata['cancellation_reason'] = $event->order->cancellation_reason ?? 'unknown';
                $metadata['cancellation_trigger'] = $event->order->cancellation_trigger ?? 'manual';
            }

            OrderTrackingEvent::create([
                'order_id' => $event->order->id,
                'event_type' => $isCancellation ? 'order.cancelled' : 'order.status.changed',
                'event_timestamp' => now(),
                'actor_type' => $event->changedByType ?? 'system',
                'actor_id' => $event->changedBy,
                'actor_name' => $this->resolveActorName($event),
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'metadata' => $metadata,
                'customer_visible' => true,
                'customer_label_key' => $isCancellation ? 'tracking.order.cancelled' : 'tracking.status.changed',
                'customer_description_key' => $isCancellation ? 'tracking.order.cancelled_description' : 'tracking.status.changed_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Order status change tracking event recorded', [
                'order_id' => $event->order->id,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'changed_by' => $event->changedBy,
                'changed_by_type' => $event->changedByType,
                'is_cancellation' => $isCancellation,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record order status change tracking event', [
                'order_id' => $event->order->id ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    /**
     * Resolve the actor name based on the actor type and ID.
     */
    private function resolveActorName(OrderStatusChanged $event): string
    {
        if (!$event->changedBy) {
            return 'System';
        }

        return match ($event->changedByType) {
            'admin' => \App\Models\User::find($event->changedBy)?->name ?? 'Admin',
            'customer' => $event->order->customer?->name ?? 'Customer',
            'courier' => 'Courier',
            default => 'System',
        };
    }
}
