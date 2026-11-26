<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\CarrierWebhookEvent;
use App\Enums\Shipping\ShippingWebhookStatus;
use App\Models\Shipment;
use App\Models\Shipping\ShippingWebhookEvent as InboxRow;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;

/**
 * #143: durable accept-once inbox.
 * #161: deterministic matching precedence AWB -> provider shipment ID -> tracking fallback -> unique order number.
 *
 * The insert is committed before the HTTP layer acknowledges the delivery.
 * A collision on the scoped unique key (provider + mode + account + event_key)
 * is a replay: it returns NULL and the caller does no further work. The raw
 * event_key is stored verbatim. Shipment linkage is read-only and every lookup
 * is scoped to the event's provider and mode, so sandbox evidence never attaches
 * to a production shipment (or vice versa). This service never writes to a
 * shipment or any other lifecycle table.
 */
final class ShippingWebhookInbox
{
    private const AWB_KEYS = ['awb', 'awb_number', 'awbNumber', 'airwaybill', 'airway_bill'];

    /** Canonical provider-shipment aliases only; tracking aliases are fallback. */
    private const PROVIDER_ID_KEYS = [
        'provider_shipment_id', 'providerShipmentId', 'carrier_shipment_id', 'carrierShipmentId',
        'shipment_reference', 'shipmentReference',
    ];

    /** Tracking aliases are only a fallback when no provider shipment id exists. */
    private const TRACKING_FALLBACK_KEYS = ['tracking_number', 'trackingNumber'];

    private const ORDER_KEYS = ['order_number', 'orderNumber'];

    public function store(CarrierWebhookEvent $event): ?InboxRow
    {
        // #160: internal delivery actions must never create external inbox rows.
        if ($event->mode === 'internal' || $event->providerCode === 'internal') {
            return null;
        }

        $match = $this->resolveShipmentMatch($event);

        try {
            return InboxRow::create([
                'provider_code' => $event->providerCode,
                'mode' => $event->mode,
                'account_code' => $event->accountCode,
                'webhook_version' => $event->webhookVersion,
                'event_key' => $event->eventKey,
                'event_type' => $event->eventType,
                'provider_shipment_ref' => $event->providerShipmentRef,
                'shipment_id' => $match['shipment_id'],
                'match_status' => $match['status'],
                'match_notes' => $match['notes'],
                'redacted_payload' => $event->redactedPayload,
                'raw_status' => $event->rawStatus,
                'status' => ShippingWebhookStatus::RECEIVED->value,
                'last_error' => null,
                'received_at' => Date::now(),
                'occurred_at' => $event->occurredAt,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    /**
     * @return array{shipment_id: ?int, status: string, notes: ?string}
     */
    private function resolveShipmentMatch(CarrierWebhookEvent $event): array
    {
        $payload = is_array($event->redactedPayload) ? $event->redactedPayload : [];

        $awbCandidates = $this->identityCandidates($event->awb, $payload, self::AWB_KEYS);
        $awbMatch = $this->matchByCandidates($event, $awbCandidates, 'awb', 'tracking');
        if ($awbMatch['status'] !== 'unresolved') {
            return $awbMatch;
        }

        $providerCandidates = $this->identityCandidates($event->providerShipmentRef, $payload, self::PROVIDER_ID_KEYS);
        $providerMatch = $this->matchByCandidates($event, $providerCandidates, 'provider shipment id', 'provider_ref');
        if ($providerMatch['status'] !== 'unresolved') {
            return $providerMatch;
        }

        $trackingCandidates = $this->identityCandidates(null, $payload, self::TRACKING_FALLBACK_KEYS);
        $trackingMatch = $this->matchByCandidates($event, $trackingCandidates, 'tracking number', 'tracking');
        if ($trackingMatch['status'] !== 'unresolved') {
            return $trackingMatch;
        }

        $orderCandidates = $this->identityCandidates($event->orderNumber, $payload, self::ORDER_KEYS);
        $orderMatch = $this->matchByCandidates($event, $orderCandidates, 'order number', 'order');
        if ($orderMatch['status'] !== 'unresolved') {
            return $orderMatch;
        }

        if ($event->shipmentUuid !== null) {
            $uuidMatch = Shipment::query()
                ->where('uuid', $event->shipmentUuid)
                ->where('provider_code', $event->providerCode)
                ->where('provider_mode', $event->mode)
                ->value('id');
            if ($uuidMatch !== null) {
                return ['shipment_id' => (int) $uuidMatch, 'status' => 'matched_uuid', 'notes' => 'matched by shipment uuid'];
            }
        }

        return ['shipment_id' => null, 'status' => 'unmatched', 'notes' => 'no unique awb/provider shipment id/order number match'];
    }

    /**
     * DTO identity and redacted aliases are both considered: conflicting aliases
     * are ambiguous rather than silently resolved by whichever key comes first.
     *
     * @param array<string,mixed> $payload
     * @param list<string> $keys
     * @return list<string>
     */
    private function identityCandidates(?string $fieldValue, array $payload, array $keys): array
    {
        $values = [$fieldValue];
        foreach ($keys as $key) {
            $values[] = data_get($payload, $key);
        }

        $normalized = [];
        foreach ($values as $value) {
            $candidate = $this->normalize(is_string($value) ? $value : null);
            if ($candidate !== null && !in_array($candidate, $normalized, true)) {
                $normalized[] = $candidate;
            }
        }

        return $normalized;
    }

    /**
     * @param list<string> $values
     * @return array{shipment_id: ?int, status: string, notes: ?string}
     */
    private function matchByCandidates(CarrierWebhookEvent $event, array $values, string $label, string $strategy): array
    {
        if (count($values) > 1) {
            return ['shipment_id' => null, 'status' => 'ambiguous', 'notes' => "conflicting {$label} identity"];
        }
        if ($values === []) {
            return ['shipment_id' => null, 'status' => 'unresolved', 'notes' => null];
        }

        return $strategy === 'order'
            ? $this->matchByOrderNumber($event, $values[0])
            : ($strategy === 'provider_ref'
                ? $this->matchByProviderShipmentId($event, $values[0], $label)
                : $this->matchByTrackingNumber($event, $values[0], $label));
    }

    /** @return array{shipment_id: ?int, status: string, notes: ?string} */
    private function matchByProviderShipmentId(CarrierWebhookEvent $event, ?string $value, string $label): array
    {
        $value = $this->normalize($value);
        if ($value === null) {
            return ['shipment_id' => null, 'status' => 'unresolved', 'notes' => null];
        }

        $ids = Shipment::query()
            ->where('provider_code', $event->providerCode)
            ->where('provider_mode', $event->mode)
            ->where('provider_shipment_id', $value)
            ->limit(2)
            ->pluck('id')
            ->all();

        if (count($ids) === 1) {
            return ['shipment_id' => (int) $ids[0], 'status' => 'matched', 'notes' => "matched by {$label}"];
        }
        if (count($ids) > 1) {
            return ['shipment_id' => null, 'status' => 'ambiguous', 'notes' => "ambiguous {$label} match"];
        }

        return ['shipment_id' => null, 'status' => 'unresolved', 'notes' => null];
    }

    /** @return array{shipment_id: ?int, status: string, notes: ?string} */
    private function matchByTrackingNumber(CarrierWebhookEvent $event, ?string $value, string $label): array
    {
        $value = $this->normalize($value);
        if ($value === null) {
            return ['shipment_id' => null, 'status' => 'unresolved', 'notes' => null];
        }

        $ids = Shipment::query()
            ->where('provider_code', $event->providerCode)
            ->where('provider_mode', $event->mode)
            ->where('tracking_number', $value)
            ->limit(2)
            ->pluck('id')
            ->all();

        if (count($ids) === 1) {
            return ['shipment_id' => (int) $ids[0], 'status' => 'matched', 'notes' => "matched by {$label}"];
        }
        if (count($ids) > 1) {
            return ['shipment_id' => null, 'status' => 'ambiguous', 'notes' => "ambiguous {$label} match"];
        }

        return ['shipment_id' => null, 'status' => 'unresolved', 'notes' => null];
    }

    /** @return array{shipment_id: ?int, status: string, notes: ?string} */
    private function matchByOrderNumber(CarrierWebhookEvent $event, ?string $value): array
    {
        $value = $this->normalize($value);
        if ($value === null) {
            return ['shipment_id' => null, 'status' => 'unresolved', 'notes' => null];
        }

        $ids = Shipment::query()
            ->where('provider_code', $event->providerCode)
            ->where('provider_mode', $event->mode)
            ->whereHas('order', function ($q) use ($value): void {
                $q->where('order_number', $value);
            })
            ->limit(2)
            ->pluck('id')
            ->all();

        if (count($ids) === 1) {
            return ['shipment_id' => (int) $ids[0], 'status' => 'matched', 'notes' => 'matched by unique order number'];
        }
        if (count($ids) > 1) {
            return ['shipment_id' => null, 'status' => 'ambiguous', 'notes' => 'ambiguous order number match'];
        }

        return ['shipment_id' => null, 'status' => 'unresolved', 'notes' => null];
    }

    private function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $v = trim($value);

        return $v === '' ? null : $v;
    }
}
