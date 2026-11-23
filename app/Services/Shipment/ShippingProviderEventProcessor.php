<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\ProviderStatusMapping;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
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
 *
 * Evidence is committed before the transition attempt. A configured map that
 * targets a shipment-state the authority refuses therefore leaves the raw
 * event and an internal exception behind instead of rolling both back.
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

        $event = DB::transaction(function () use ($webhook, $rawStatus, $mapping): ?ShippingProviderEvent {
            $event = $this->storeProviderEvent($webhook, $rawStatus, $mapping);
            if ($event === null) {
                return null;
            }

            $this->recordMappingException($event, $webhook, $rawStatus, $mapping);

            return $event;
        });

        if ($event === null) {
            return ShippingProviderEvent::query()->where('webhook_event_id', $webhook->id)->firstOrFail();
        }

        $this->applyTransition($event, $webhook, $rawStatus, $mapping);

        return $event->fresh();
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

    private function recordMappingException(
        ShippingProviderEvent $event,
        ShippingWebhookEvent $webhook,
        ?string $rawStatus,
        ProviderStatusMapping $mapping,
    ): void {
        if (!$mapping->canTransition()) {
            $this->raiseException(
                $event,
                $mapping->exceptionType ?? ProviderEventExceptionType::UNKNOWN_STATUS,
                $this->exceptionNotes($rawStatus, $mapping->mappingVersion),
            );

            return;
        }

        if ($webhook->shipment_id === null) {
            $event->update(['exception_classification' => ProviderEventExceptionType::UNMATCHED_SHIPMENT->value]);
            $this->raiseException(
                $event,
                ProviderEventExceptionType::UNMATCHED_SHIPMENT,
                $this->exceptionNotes($rawStatus, $mapping->mappingVersion),
            );
        }
    }

    private function applyTransition(
        ShippingProviderEvent $event,
        ShippingWebhookEvent $webhook,
        ?string $rawStatus,
        ProviderStatusMapping $mapping,
    ): void {
        if (!$mapping->canTransition() || $webhook->shipment_id === null) {
            return;
        }

        try {
            $this->shipments->updateStatus(
                (int) $webhook->shipment_id,
                (string) $mapping->mappedStatus,
                'provider_status_map_version:'.$mapping->mappingVersion,
            );
            $event->update(['applied_at' => now()]);
        } catch (\RuntimeException $exception) {
            // The event row was already committed. Keep it, mark the mapping
            // unusable for this shipment, and surface the failure internally.
            $event->update([
                'exception_classification' => ProviderEventExceptionType::INVALID_MAPPING_CONFIG->value,
            ]);
            $this->raiseException(
                $event,
                ProviderEventExceptionType::INVALID_MAPPING_CONFIG,
                $this->exceptionNotes($rawStatus, $mapping->mappingVersion, $exception->getMessage()),
            );
        }
    }

    private function raiseException(
        ShippingProviderEvent $event,
        ProviderEventExceptionType $type,
        string $notes,
    ): void {
        ShipmentException::firstOrCreate(
            ['provider_event_id' => $event->id],
            [
                'shipment_id' => $event->shipment_id,
                'type' => $type->value,
                'severity' => ShipmentExceptionSeverity::HIGH->value,
                'status' => ShipmentExceptionStatus::OPEN->value,
                'notes' => $notes,
                'opened_at' => now(),
            ],
        );
    }

    private function exceptionNotes(
        ?string $rawStatus,
        string $mappingVersion,
        ?string $transitionError = null,
    ): string {
        $notes = 'raw_status='.($rawStatus ?? '<missing>').'; mapping_version='.($mappingVersion !== '' ? $mappingVersion : '<none>');
        if ($transitionError !== null && $transitionError !== '') {
            $notes .= '; transition_error='.$transitionError;
        }

        return $notes;
    }
}
