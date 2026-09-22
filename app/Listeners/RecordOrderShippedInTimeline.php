<?php

namespace App\Listeners;

use App\Events\OrderShipped;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordOrderShippedInTimeline
{
    public function handle(OrderShipped $event): void
    {
        try {
            $actorName = $this->resolveActorName($event->shippedByType, $event->shippedBy, $event->order);

            OrderTrackingEvent::create([
                'order_id' => $event->order->id,
                'event_type' => 'shipment.created',
                'event_timestamp' => now(),
                'actor_type' => $event->shippedByType,
                'actor_id' => $event->shippedBy,
                'actor_name' => $actorName,
                'old_status' => null,
                'new_status' => null,
                'metadata' => [
                    'tracking_number' => $event->trackingNumber,
                    'carrier' => $event->carrier,
                    'order_number' => $event->order->order_number,
                    'shipped_at' => now()->toIso8601String(),
                ],
                'customer_visible' => true,
                'customer_label_key' => 'tracking.shipment.created',
                'customer_description_key' => 'tracking.shipment.created_description',
                'admin_notes' => null,
                'source' => 'system',
                'ip_address' => request()->ip(),
            ]);

            Log::info('Order shipped tracking event recorded', [
                'order_id' => $event->order->id,
                'tracking_number' => $event->trackingNumber,
                'carrier' => $event->carrier,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to record order shipped tracking event', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function resolveActorName(string $actorType, ?int $actorId, $order): string
    {
        return match ($actorType) {
            'admin' => \App\Models\User::find($actorId)?->name ?? 'Admin User',
            'customer' => $order->customer?->name ?? 'Customer',
            'courier' => 'Courier',
            default => 'System',
        };
    }
}
