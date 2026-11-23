<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\ProviderStatusMapping;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Exceptions\CarrierConfigurationException;
use App\Models\Shipment;
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
        private readonly CarrierGatewayFactory $factories,
    ) {}

    public function process(ShippingWebhookEvent $webhook): ShippingProviderEvent
    {
        $existing = ShippingProviderEvent::query()->where('webhook_event_id', $webhook->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $rawStatus = $this->rawStatusFrom($webhook);
        // #149: compare the event's environment with the shipment snapshot
        // before any mapping is allowed to reach the canonical authority.
        $modeMismatch = $this->modeMismatchFor($webhook);
        $mapping = $this->mapper->map($webhook->provider_code, $webhook->mode, $rawStatus);

        $event = DB::transaction(function () use ($webhook, $rawStatus, $mapping, $modeMismatch): ?ShippingProviderEvent {
            $event = $this->storeProviderEvent($webhook, $rawStatus, $mapping);
            if ($event === null) {
                return null;
            }

            $this->recordMappingException($event, $mapping, $modeMismatch);

            return $event;
        });

        if ($event === null) {
            return ShippingProviderEvent::query()->where('webhook_event_id', $webhook->id)->firstOrFail();
        }

        $this->applyTransition($event, $mapping, $modeMismatch);

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

    private function modeMismatchFor(ShippingWebhookEvent $webhook): ?string
    {
        if ($webhook->shipment_id === null) {
            return null;
        }

        $shipment = Shipment::query()->find((int) $webhook->shipment_id);
        if ($shipment === null) {
            return null;
        }

        try {
            $this->factories->assertShipmentMode($shipment, is_string($webhook->mode) ? $webhook->mode : '');
        } catch (CarrierConfigurationException $exception) {
            return $exception->getMessage();
        }

        return null;
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
                'mapped_status' => $mapping->mappedStatus?->value,
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
        ProviderStatusMapping $mapping,
        ?string $modeMismatch,
    ): void {
        $notes = $this->withModeMismatchNote(
            $this->exceptionNotes($event->raw_status, $mapping->mappingVersion),
            $modeMismatch,
        );

        if ($modeMismatch !== null) {
            $event->update(['exception_classification' => ProviderEventExceptionType::MODE_MISMATCH->value]);
            $this->raiseException(
                $event,
                ProviderEventExceptionType::MODE_MISMATCH,
                $notes,
            );

            return;
        }

        if (!$mapping->canTransition()) {
            $this->raiseException(
                $event,
                $mapping->exceptionType ?? ProviderEventExceptionType::UNKNOWN_STATUS,
                $notes,
            );

            return;
        }

        if ($event->shipment_id === null) {
            $event->update(['exception_classification' => ProviderEventExceptionType::UNMATCHED_SHIPMENT->value]);
            $this->raiseException($event, ProviderEventExceptionType::UNMATCHED_SHIPMENT, $notes);
        }
    }

    private function applyTransition(
        ShippingProviderEvent $event,
        ProviderStatusMapping $mapping,
        ?string $modeMismatch,
    ): void {
        if ($modeMismatch !== null || !$mapping->canTransition() || $event->shipment_id === null) {
            return;
        }

        try {
            // One transaction: a failed evidence mark must not leave a status
            // transition behind, since replay short-circuits on the event row.
            DB::transaction(function () use ($event, $mapping): void {
                $this->shipments->updateStatus(
                    (int) $event->shipment_id,
                    $mapping->mappedStatus->value,
                    'provider_status_map_version:'.$mapping->mappingVersion,
                );
                $event->update(['applied_at' => now()]);
            });
        } catch (\RuntimeException $exception) {
            // The event row was already committed. Keep it, mark the mapping
            // unusable for this shipment, and surface the failure internally.
            $event->refresh();
            $event->update([
                'exception_classification' => ProviderEventExceptionType::INVALID_MAPPING_CONFIG->value,
            ]);
            $this->raiseException(
                $event,
                ProviderEventExceptionType::INVALID_MAPPING_CONFIG,
                $this->exceptionNotes($event->raw_status, $mapping->mappingVersion, $exception->getMessage()),
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

    private function withModeMismatchNote(string $notes, ?string $modeMismatch): string
    {
        return $modeMismatch === null || $modeMismatch === ''
            ? $notes
            : $notes.'; mode_mismatch='.$modeMismatch;
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
