<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\ProviderStatusMapping;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * #151: turns an accepted webhook inbox row into durable provider evidence and,
 * only for a known unambiguous versioned mapping, a canonical shipment
 * transition through ShipmentService. The inbox ingest boundary never calls
 * this service; processing is a separate, replay-safe step.
 */
final class ShippingProviderEventProcessor
{
    public function __construct(
        private readonly CarrierStatusMapper $mapper,
        private readonly ShipmentService $shipments,
    ) {}

    public function process(ShippingWebhookEvent $webhook): ShippingProviderEvent
    {
        $existing = ShippingProviderEvent::query()->where('webhook_event_id', $webhook->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $rawStatus = $this->rawStatusFrom($webhook);
        $mapping = $this->mapper->map($webhook->provider_code, $webhook->mode, $rawStatus);

        return DB::transaction(function () use ($webhook, $rawStatus, $mapping): ShippingProviderEvent {
            $event = $this->storeProviderEvent($webhook, $rawStatus, $mapping);
            if ($event === null) {
                return ShippingProviderEvent::query()->where('webhook_event_id', $webhook->id)->firstOrFail();
            }

            $this->recordOutcome($event, $webhook, $rawStatus, $mapping);

            return $event->fresh();
        });
    }

    private function rawStatusFrom(ShippingWebhookEvent $webhook): ?string
    {
        if ($webhook->raw_status !== null) {
            return $webhook->raw_status;
        }

        $status = is_array($webhook->redacted_payload)
            ? ($webhook->redacted_payload['status'] ?? null)
            : null;

        return is_string($status) ? $status : null;
    }

    private function storeProviderEvent(
        ShippingWebhookEvent $webhook,
        ?string $rawStatus,
        ProviderStatusMapping $mapping,
    ): ?ShippingProviderEvent {
        $payloadHash = hash('sha256', json_encode($webhook->redacted_payload ?? [], JSON_THROW_ON_ERROR));

        try {
            return ShippingProviderEvent::create([
                'webhook_event_id' => $webhook->id,
                'shipment_id' => $webhook->shipment_id,
                'provider_code' => $webhook->provider_code,
                'mode' => $webhook->mode,
                'account_code' => $webhook->account_code,
                'provider_event_key' => $webhook->event_key,
                'raw_status' => $rawStatus,
                'mapping_version' => $mapping->mappingVersion,
                'mapped_status' => $mapping->mappedStatus,
                'exception_classification' => $mapping->exceptionType?->value,
                'payload_reference' => 'shipping_webhook_event:'.$webhook->id,
                'payload_hash' => $payloadHash,
                'applied_at' => null,
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }
    }

    private function recordOutcome(
        ShippingProviderEvent $event,
        ShippingWebhookEvent $webhook,
        ?string $rawStatus,
        ProviderStatusMapping $mapping,
    ): void {
        if (!$mapping->canTransition()) {
            $this->raiseException(
                $event,
                $mapping->exceptionType ?? ProviderEventExceptionType::UNKNOWN_STATUS,
                $rawStatus,
                $mapping->mappingVersion,
            );

            return;
        }

        if ($webhook->shipment_id === null) {
            $event->update(['exception_classification' => ProviderEventExceptionType::UNMATCHED_SHIPMENT->value]);
            $this->raiseException(
                $event,
                ProviderEventExceptionType::UNMATCHED_SHIPMENT,
                $rawStatus,
                $mapping->mappingVersion,
            );

            return;
        }

        $this->shipments->updateStatus(
            (int) $webhook->shipment_id,
            (string) $mapping->mappedStatus,
            'provider_status_map_version:'.$mapping->mappingVersion,
        );
        $event->update(['applied_at' => now()]);
    }

    private function raiseException(
        ShippingProviderEvent $event,
        ProviderEventExceptionType $type,
        ?string $rawStatus,
        string $mappingVersion,
    ): void {
        ShipmentException::firstOrCreate(
            ['provider_event_id' => $event->id],
            [
                'shipment_id' => $event->shipment_id,
                'type' => $type->value,
                'severity' => 'high',
                'status' => 'open',
                'notes' => 'raw_status='.($rawStatus ?? '<missing>').'; mapping_version='.($mappingVersion !== '' ? $mappingVersion : '<none>'),
                'opened_at' => now(),
            ],
        );
    }
}
