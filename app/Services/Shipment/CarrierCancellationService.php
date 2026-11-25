<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\CarrierShipment;
use App\Enums\Shipping\CarrierCapability;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Exceptions\CarrierConfigurationException;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use Throwable;

/**
 * #187: provider-confirmed cancellation boundary.
 *
 * A remote carrier cancellation is confirmed BEFORE the canonical local
 * authority records `cancelled`. Unsupported configuration and provider
 * refusals retain local state and open an operational exception. The internal
 * gateway is the local provider and delegates to the canonical authority
 * without a second outbound identity.
 */
final class CarrierCancellationService
{
    private const INTERNAL_PROVIDER = 'internal';

    public function __construct(
        private readonly CarrierGatewayFactory $factories,
        private readonly ShipmentService $shipments,
    ) {}

    /**
     * @param array{cancelled_by?: int|null, cancel_source?: string} $context
     */
    public function cancel(int $shipmentId, string $reason, array $context = []): Shipment
    {
        $shipment = Shipment::query()->findOrFail($shipmentId);
        $providerCode = is_string($shipment->provider_code) ? trim($shipment->provider_code) : '';

        if ($providerCode === self::INTERNAL_PROVIDER) {
            return $this->shipments->cancelShipment($shipmentId, $reason, $context);
        }

        $externalShipmentId = is_string($shipment->tracking_number) && trim($shipment->tracking_number) !== ''
            ? trim($shipment->tracking_number)
            : (string) $shipment->getKey();

        try {
            $gateway = $this->factories->makeForShipment($shipment, CarrierCapability::CANCEL_SHIPMENT);
        } catch (CarrierConfigurationException $exception) {
            $this->raise($shipment, ProviderEventExceptionType::CANCEL_UNSUPPORTED, $exception->getMessage());

            throw $exception;
        }

        if (! $gateway instanceof \App\Services\Shipment\Contracts\CancelsCarrierShipments) {
            $message = "Carrier provider [{$providerCode}] adapter does not implement cancellation.";
            $this->raise($shipment, ProviderEventExceptionType::CANCEL_UNSUPPORTED, $message);

            throw new CarrierConfigurationException($message);
        }

        try {
            $confirmation = $gateway->cancelShipment($externalShipmentId);
        } catch (Throwable $exception) {
            $this->raise($shipment, ProviderEventExceptionType::CANCEL_REJECTED, $exception->getMessage());

            throw $exception;
        }

        if (! $confirmation instanceof CarrierShipment || trim($confirmation->providerCode) !== $providerCode) {
            $message = 'Provider cancellation confirmation did not identify the shipment provider.';
            $this->raise($shipment, ProviderEventExceptionType::CANCEL_REJECTED, $message);

            throw new CarrierConfigurationException($message);
        }

        return $this->shipments->cancelShipment($shipmentId, $reason, $context);
    }

    private function raise(Shipment $shipment, ProviderEventExceptionType $type, string $notes): void
    {
        ShipmentException::query()->firstOrCreate(
            [
                'shipment_id' => $shipment->id,
                'type' => $type->value,
                'status' => ShipmentExceptionStatus::OPEN->value,
            ],
            [
                'provider_event_id' => null,
                'severity' => ShipmentExceptionSeverity::HIGH->value,
                'notes' => $notes,
                'opened_at' => now(),
            ],
        );
    }
}
