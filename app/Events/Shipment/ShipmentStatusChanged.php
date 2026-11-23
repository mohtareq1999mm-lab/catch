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
}
