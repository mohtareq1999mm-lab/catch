<?php

declare(strict_types=1);

namespace Tests\Support\Shipping;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Shipment;
use App\Services\Shipment\ShipmentService;

/**
 * Test double at the canonical shipment authority seam.
 *
 * It delegates to the real service so lifecycle guarantees stay intact while
 * the test observes which canonical method a provider adapter called.
 */
final class RecordingShipmentService extends ShipmentService
{
    /** @var list<array{method: string, args: array<string,mixed>}> */
    public array $calls = [];

    public function createForFulfillment(Fulfillment $fulfillment, array $data = [], ?string $idempotencyKey = null): Shipment
    {
        $this->record('createForFulfillment', [
            'fulfillment_id' => (int) $fulfillment->id,
            'idempotency_key' => $idempotencyKey,
        ]);

        return parent::createForFulfillment($fulfillment, $data, $idempotencyKey);
    }

    public function dispatch(int $shipmentId, ?string $notes = null): Shipment
    {
        $this->record('dispatch', ['shipment_id' => $shipmentId]);

        return parent::dispatch($shipmentId, $notes);
    }

    public function markDelivered(int $shipmentId, ?string $notes = null): Shipment
    {
        $this->record('markDelivered', ['shipment_id' => $shipmentId]);

        return parent::markDelivered($shipmentId, $notes);
    }

    public function cancelShipment(int $shipmentId, string $reason, array $context = []): Shipment
    {
        $this->record('cancelShipment', [
            'shipment_id' => $shipmentId,
            'reason' => $reason,
            'cancel_source' => $context['cancel_source'] ?? null,
        ]);

        return parent::cancelShipment($shipmentId, $reason, $context);
    }

    /** @param array<string,mixed> $args */
    private function record(string $method, array $args): void
    {
        $this->calls[] = ['method' => $method, 'args' => $args];
    }
}
