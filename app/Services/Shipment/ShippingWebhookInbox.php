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
 *
 * The insert is committed before the HTTP layer acknowledges the delivery.
 * A collision on the scoped unique key (provider + mode + account + event_key)
 * is a replay: it returns NULL and the caller does no further work. The raw
 * event_key is stored verbatim. Shipment linkage is a read-only UUID lookup;
 * this service never writes to a shipment or any other lifecycle table.
 */
final class ShippingWebhookInbox
{
    public function store(CarrierWebhookEvent $event): ?InboxRow
    {
        // #160: internal delivery actions must never create external inbox rows.
        if ($event->mode === 'internal' || $event->providerCode === 'internal') {
            return null;
        }

        $shipmentId = null;
        if ($event->shipmentUuid !== null) {
            $shipmentId = Shipment::query()
                ->where('uuid', $event->shipmentUuid)
                ->value('id');
        }

        try {
            return InboxRow::create([
                'provider_code' => $event->providerCode,
                'mode' => $event->mode,
                'account_code' => $event->accountCode,
                'webhook_version' => $event->webhookVersion,
                'event_key' => $event->eventKey,
                'event_type' => $event->eventType,
                'provider_shipment_ref' => $event->providerShipmentRef,
                'shipment_id' => $shipmentId,
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
}

