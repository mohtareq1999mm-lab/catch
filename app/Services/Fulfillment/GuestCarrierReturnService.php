<?php

declare(strict_types=1);

namespace App\Services\Fulfillment;

use App\DTOs\Shipping\CarrierReturnLink;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Models\Fulfillment\ReturnRequest;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShippingProviderEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;
use Marvel\Database\Models\Order;

/**
 * #181: a carrier return for a guest order is operational evidence only.
 *
 * This service deliberately does not call ReturnService: a return request
 * needs a real customer and real return items, neither of which exists on a
 * guest order. When no evidence-bearing return request is already linked to
 * the shipment, the carrier return is recorded as an internal exception for
 * manual review. A provider event is mandatory because shipment_exceptions
 * deduplicates on its unique provider_event_id.
 */
final class GuestCarrierReturnService
{
    /** @var list<string> */
    private const SAFE_EXTERNAL_IDS = [
        'order_id',
        'shipment_id',
        'return_id',
        'return_reference',
        'carrier_reference',
        'provider_return_id',
        'tracking_number',
        'awb',
        'waybill',
    ];

    public function record(
        Shipment $shipment,
        CarrierReturnLink $link,
        ShippingProviderEvent $providerEvent,
    ): ?ShipmentException {
        $order = $this->guestOrder($shipment);
        $this->assertProviderEvent($shipment, $providerEvent);
        $this->assertMatchingIdentity($link, 'order_id', (int) $order->getKey());
        $this->assertMatchingIdentity($link, 'shipment_id', (int) $shipment->getKey());

        if ($this->hasSuitableReturnRequest($shipment)) {
            return null;
        }

        // The unique provider_event_id is the only exception identity. Never
        // rewrite an existing event exception or race a second insert.
        if (ShipmentException::query()->where('provider_event_id', $providerEvent->id)->exists()) {
            return null;
        }

        try {
            return ShipmentException::firstOrCreate(
                ['provider_event_id' => $providerEvent->id],
                $this->exceptionAttributes($shipment, $order, $link, $providerEvent),
            );
        } catch (UniqueConstraintViolationException) {
            return ShipmentException::query()->where('provider_event_id', $providerEvent->id)->first();
        }
    }

    private function guestOrder(Shipment $shipment): Order
    {
        $order = $shipment->order;
        if (!$order instanceof Order || !$order->exists) {
            throw new InvalidArgumentException('Carrier return requires a shipment with an order.');
        }
        if ($order->user_id !== null || $order->guest_id === null) {
            throw new InvalidArgumentException('Guest carrier return requires a guest order.');
        }

        return $order;
    }

    private function assertProviderEvent(Shipment $shipment, ShippingProviderEvent $providerEvent): void
    {
        if ((int) $providerEvent->shipment_id !== (int) $shipment->getKey()) {
            throw new InvalidArgumentException('Provider event does not belong to this shipment.');
        }
        if ($providerEvent->provider_code !== $shipment->provider_code
            || $providerEvent->mode !== $shipment->provider_mode
        ) {
            throw new InvalidArgumentException('Provider event identity does not match the shipment.');
        }
    }

    /** @return array<string,mixed> */
    private function exceptionAttributes(
        Shipment $shipment,
        Order $order,
        CarrierReturnLink $link,
        ShippingProviderEvent $providerEvent,
    ): array {
        return [
            'shipment_id' => (int) $shipment->getKey(),
            'type' => ProviderEventExceptionType::GUEST_CARRIER_RETURN->value,
            'severity' => ShipmentExceptionSeverity::HIGH->value,
            'status' => ShipmentExceptionStatus::OPEN->value,
            'notes' => $this->notes($shipment, $order, $link, $providerEvent),
            'opened_at' => now(),
        ];
    }

    private function notes(
        Shipment $shipment,
        Order $order,
        CarrierReturnLink $link,
        ShippingProviderEvent $providerEvent,
    ): string {
        return json_encode([
            'reason' => 'no_suitable_return_request',
            'order_id' => (int) $order->getKey(),
            'shipment_id' => (int) $shipment->getKey(),
            'provider_code' => $link->providerCode,
            'mode' => $link->mode,
            'provider_event_id' => (int) $providerEvent->id,
            'provider_event_key' => $providerEvent->provider_event_key,
            'recorded_at' => $providerEvent->occurred_at?->toIso8601String(),
            'external_ids' => $this->safeExternalIds($link->externalIds),
            'redacted_payload_reference' => $link->redactedPayloadReference,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function hasSuitableReturnRequest(Shipment $shipment): bool
    {
        return ReturnRequest::query()
            ->where('order_id', (int) $shipment->order_id)
            ->where('shipment_id', (int) $shipment->getKey())
            ->whereNotNull('customer_id')
            ->whereNotIn('status', ['completed', 'cancelled', 'rejected'])
            ->whereHas('returnItems', static fn ($query) => $query->where('quantity_returned', '>', 0))
            ->exists();
    }

    private function assertMatchingIdentity(CarrierReturnLink $link, string $key, int $expected): void
    {
        $presented = $link->externalIds[$key] ?? null;
        if ($presented === null) {
            return;
        }

        if (preg_match('/^[1-9][0-9]*$/', $presented) !== 1 || (int) $presented !== $expected) {
            throw new InvalidArgumentException("Carrier return {$key} does not match the shipment.");
        }
    }

    /** @param array<string,string> $externalIds @return array<string,string> */
    private function safeExternalIds(array $externalIds): array
    {
        $safe = [];
        foreach ($externalIds as $key => $value) {
            if (in_array($key, self::SAFE_EXTERNAL_IDS, true)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }
}
