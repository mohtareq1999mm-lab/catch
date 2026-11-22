<?php

declare(strict_types=1);

namespace App\Services\Shipment\Providers;

use App\DTOs\Shipping\CarrierGatewayContext;
use App\DTOs\Shipping\CarrierShipment;
use App\Enums\Shipping\CarrierCapability;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Shipment;
use App\Services\Shipment\Contracts\CancelsCarrierShipments;
use App\Services\Shipment\Contracts\CarrierGatewayContract;
use App\Services\Shipment\Contracts\CreatesCarrierShipments;
use App\Services\Shipment\ShipmentService;
use InvalidArgumentException;

/**
 * Built-in local delivery adapter.
 *
 * It carries no credentials and starts no webhook consumer. Every mutation is
 * delegated to ShipmentService, which remains the single shipment authority;
 * this class only translates the narrow provider seam into canonical calls.
 */
final class InternalDeliveryGateway implements CarrierGatewayContract, CreatesCarrierShipments, CancelsCarrierShipments
{
    /**
     * Pricing, ownership and status fields are deliberately not accepted here;
     * ShipmentService owns them.
     */
    private const CREATE_FIELDS = [
        'tracking_number', 'shipping_method', 'origin_address',
        'destination_address', 'items', 'total_weight', 'weight_unit',
        'estimated_delivery_at', 'notes', 'metadata',
    ];

    public function __construct(
        private readonly CarrierGatewayContext $context,
        private readonly ShipmentService $shipments,
    ) {}

    public function context(): CarrierGatewayContext
    {
        return $this->context;
    }

    /** @return list<CarrierCapability> */
    public function capabilities(): array
    {
        return [CarrierCapability::CREATE_SHIPMENT, CarrierCapability::CANCEL_SHIPMENT];
    }

    /** @param array<string,mixed> $parameters */
    public function createShipment(array $parameters): CarrierShipment
    {
        $fulfillment = Fulfillment::findOrFail($this->positiveInt($parameters['fulfillment_id'] ?? null, 'fulfillment id'));

        $data = ['courier' => $this->context->providerCode];
        foreach (self::CREATE_FIELDS as $field) {
            if (array_key_exists($field, $parameters)) {
                $data[$field] = $parameters[$field];
            }
        }

        $idempotencyKey = $parameters['idempotency_key'] ?? null;
        $idempotencyKey = is_string($idempotencyKey) && trim($idempotencyKey) !== '' ? trim($idempotencyKey) : null;

        return $this->toCarrierShipment($this->shipments->createForFulfillment($fulfillment, $data, $idempotencyKey));
    }

    public function cancelShipment(string $externalShipmentId): CarrierShipment
    {
        return $this->toCarrierShipment($this->shipments->cancelShipment(
            $this->positiveInt($externalShipmentId, 'shipment id'),
            __('wms.shipment.internal_cancel_reason'),
            ['cancel_source' => $this->context->providerCode],
        ));
    }

    public function dispatchShipment(string $externalShipmentId, ?string $notes = null): CarrierShipment
    {
        return $this->toCarrierShipment($this->shipments->dispatch($this->positiveInt($externalShipmentId, 'shipment id'), $notes));
    }

    public function markShipmentDelivered(string $externalShipmentId, ?string $notes = null): CarrierShipment
    {
        return $this->toCarrierShipment($this->shipments->markDelivered($this->positiveInt($externalShipmentId, 'shipment id'), $notes));
    }

    private function positiveInt(mixed $candidate, string $label): int
    {
        if (is_int($candidate) && $candidate > 0) {
            return $candidate;
        }
        if (is_string($candidate)) {
            $normalized = trim($candidate);
            if (preg_match('/^[1-9][0-9]*$/', $normalized) === 1 && (string) (int) $normalized === $normalized) {
                return (int) $normalized;
            }
        }

        throw new InvalidArgumentException("Internal delivery requires a positive {$label}.");
    }

    private function toCarrierShipment(Shipment $shipment): CarrierShipment
    {
        $id = (string) $shipment->id;

        return new CarrierShipment(
            $this->context->providerCode,
            $this->context->mode,
            ['shipment_id' => $id],
            null,
            'internal:shipment:' . $id,
        );
    }
}
