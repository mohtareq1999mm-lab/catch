<?php

namespace App\Events\Shipment;

use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Order;

/**
 * A committed canonical shipment transition.
 *
 * The event is deliberately deferred until the transition transaction commits
 * (event-level ShouldDispatchAfterCommit): no consumer can observe a status
 * whose timeline row or order mirror has not been persisted yet. The timeline
 * id is the persisted identity of that history row.
 */
class ShipmentStatusChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public string $oldStatus,
        public string $newStatus,
        public ?string $trackingNumber = null,
        public ?string $courierName = null,
        public ?array $locationData = null,
        public ?\DateTimeInterface $estimatedDelivery = null,
        public ?int $timelineEventId = null,
        public ?int $shipmentId = null,
        public ?int $orderId = null,
        public ?int $actorId = null,
        public string $actorType = 'system',
        public string $source = 'direct',
    ) {
        $this->orderId ??= (int) $order->id;
    }

    /**
     * Build the event from the row written inside the transition transaction.
     * `shipment` is nullable for the legacy order-mirror endpoint, which has
     * no canonical shipment row but still writes a canonical timeline row.
     */
    public static function forTransition(
        Order $order,
        OrderTrackingEvent $timeline,
        ?Shipment $shipment = null,
        array $context = [],
    ): self {
        return new self(
            order: $order,
            oldStatus: (string) $timeline->old_status,
            newStatus: (string) $timeline->new_status,
            trackingNumber: $context['tracking_number'] ?? $shipment?->tracking_number,
            courierName: $context['courier_name'] ?? $shipment?->courier,
            locationData: $context['location_data'] ?? null,
            estimatedDelivery: $context['estimated_delivery_at'] ?? $shipment?->estimated_delivery_at,
            timelineEventId: (int) $timeline->id,
            shipmentId: $shipment ? (int) $shipment->id : null,
            orderId: (int) $order->id,
            actorId: isset($context['actor_id']) ? (int) $context['actor_id'] : null,
            actorType: (string) ($context['actor_type'] ?? 'system'),
            source: (string) ($context['source'] ?? 'direct'),
        );
    }

    /**
     * Rebuild the same event from the persisted timeline identity.
     *
     * Timeline rows are written inside the transition transaction (actor,
     * source and old/new status included), so replay can recover the canonical
     * payload from the timeline id alone. Recorder dedupe keys on that same id
     * make a replay safe: no second notification or history row is created.
     */
    public static function forTimelineEvent(
        OrderTrackingEvent $timeline,
        ?Shipment $shipment = null,
        array $context = [],
    ): self {
        $metadata = is_array($timeline->metadata) ? $timeline->metadata : [];
        $estimated = $context['estimated_delivery_at'] ?? ($metadata['estimated_delivery'] ?? null);

        if (is_string($estimated)) {
            try {
                $estimated = new \DateTimeImmutable($estimated);
            } catch (\Exception) {
                $estimated = null;
            }
        }

        $shipmentId = $shipment ? (int) $shipment->id : (isset($context['shipment_id']) ? (int) $context['shipment_id'] : null);

        return new self(
            order: Order::withoutGlobalScopes()->whereKey((int) $timeline->order_id)->firstOrFail(),
            oldStatus: (string) $timeline->old_status,
            newStatus: (string) $timeline->new_status,
            trackingNumber: isset($context['tracking_number'])
                ? (string) $context['tracking_number']
                : (isset($metadata['tracking_number']) ? (string) $metadata['tracking_number'] : null),
            courierName: isset($context['courier_name'])
                ? (string) $context['courier_name']
                : (isset($metadata['courier_name']) ? (string) $metadata['courier_name'] : null),
            locationData: $context['location_data'] ?? ($metadata['location_data'] ?? null),
            estimatedDelivery: $estimated instanceof \DateTimeInterface ? $estimated : null,
            timelineEventId: (int) $timeline->id,
            shipmentId: $shipmentId ?: null,
            orderId: (int) $timeline->order_id,
            actorId: $timeline->actor_id !== null ? (int) $timeline->actor_id : null,
            actorType: (string) ($timeline->actor_type ?: 'system'),
            source: (string) ($timeline->source ?: 'direct'),
        );
    }
}
