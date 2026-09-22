<?php

namespace App\Listeners\Shipment;

use App\Events\Shipment\EstimatedDeliveryChanged;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordETAChangeInTimeline
{
    public function handle(EstimatedDeliveryChanged $event): void
    {
        try {
            $order = $event->order;

            $isDelayed = $event->oldEta && $event->newEta > $event->oldEta;
            $delayDays = $event->oldEta
                ? $event->newEta->diff($event->oldEta)->days
                : 0;

            $metadata = [
                'old_eta' => $event->oldEta?->toIso8601String(),
                'new_eta' => $event->newEta->toIso8601String(),
                'reason' => $event->reason,
                'is_delayed' => $isDelayed,
                'delay_days' => $delayDays,
                'changed_at' => now()->toIso8601String(),
            ];

            $eventType = $isDelayed ? 'shipment.eta_delayed' : 'shipment.eta_updated';

            OrderTrackingEvent::create([
                'order_id' => $order->id,
                'event_type' => $eventType,
                'event_timestamp' => now(),
                'actor_type' => 'courier',
                'actor_name' => $order->courier_name ?? 'Courier',
                'old_status' => $event->oldEta?->format('Y-m-d'),
                'new_status' => $event->newEta->format('Y-m-d'),
                'metadata' => $metadata,
                'customer_visible' => true,
                'customer_label_key' => $isDelayed
                    ? 'tracking.shipment.eta_delayed'
                    : 'tracking.shipment.eta_updated',
                'customer_description_key' => $isDelayed
                    ? 'tracking.shipment.eta_delayed_description'
                    : 'tracking.shipment.eta_updated_description',
                'admin_notes' => null,
                'source' => 'courier',
                'ip_address' => request()->ip(),
            ]);

            $order->update([
                'estimated_delivery_at' => $event->newEta,
            ]);

            Log::info('ETA change recorded', [
                'order_id' => $order->id,
                'is_delayed' => $isDelayed,
                'new_eta' => $event->newEta->toDateString(),
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to record ETA change', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
