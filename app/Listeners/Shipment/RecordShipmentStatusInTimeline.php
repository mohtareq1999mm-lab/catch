<?php

namespace App\Listeners\Shipment;

use App\Events\Shipment\ShipmentStatusChanged;
use App\Models\OrderTrackingEvent;
use Illuminate\Support\Facades\Log;

class RecordShipmentStatusInTimeline
{
    public function handle(ShipmentStatusChanged $event): void
    {
        try {
            $order = $event->order;

            $metadata = [
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'tracking_number' => $event->trackingNumber,
                'courier_name' => $event->courierName,
                'location_data' => $event->locationData,
                'estimated_delivery' => $event->estimatedDelivery?->toIso8601String(),
                'changed_at' => now()->toIso8601String(),
            ];

            [$customerVisible, $icon] = $this->getVisibilityAndIcon($event->newStatus);

            OrderTrackingEvent::create([
                'order_id' => $order->id,
                'event_type' => 'shipment.status_changed',
                'event_timestamp' => now(),
                'actor_type' => 'courier',
                'actor_name' => $event->courierName ?? 'Courier',
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'metadata' => $metadata,
                'customer_visible' => $customerVisible,
                'customer_label_key' => "tracking.shipment.{$event->newStatus}",
                'customer_description_key' => "tracking.shipment.{$event->newStatus}_description",
                'admin_notes' => null,
                'source' => 'courier',
                'ip_address' => request()->ip(),
            ]);

            $order->update([
                'shipment_status' => $event->newStatus,
                'tracking_number' => $event->trackingNumber ?? $order->tracking_number,
                'courier_name' => $event->courierName ?? $order->courier_name,
                'estimated_delivery_at' => $event->estimatedDelivery ?? $order->estimated_delivery_at,
                'actual_delivery_at' => $event->newStatus === 'delivered' ? now() : $order->actual_delivery_at,
            ]);

            Log::info('Shipment status change recorded', [
                'order_id' => $order->id,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'tracking_number' => $event->trackingNumber,
            ]);

        } catch (\Exception $e) {
            Log::error('Failed to record shipment status change', [
                'order_id' => $event->order->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }

    private function getVisibilityAndIcon(string $status): array
    {
        return match($status) {
            'pending' => [false, 'clock'],
            'label_created' => [false, 'file-text'],
            'picked_up' => [true, 'package'],
            'in_transit' => [true, 'truck'],
            'out_for_delivery' => [true, 'map-pin'],
            'delivered' => [true, 'check-circle'],
            'failed_delivery' => [true, 'alert-triangle'],
            'returned' => [true, 'arrow-left'],
            'cancelled' => [true, 'x-circle'],
            default => [true, 'package'],
        };
    }
}
