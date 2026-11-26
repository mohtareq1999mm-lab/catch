<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\ProviderStatusMapping;
use App\Enums\ShipmentStatus;
use App\Enums\Shipping\ProviderEventExceptionType;
use App\Enums\Shipping\ShipmentExceptionSeverity;
use App\Enums\Shipping\ShipmentExceptionStatus;
use App\Exceptions\CarrierConfigurationException;
use App\Exceptions\CarrierReturnLinkSkipped;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentException;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Shipment\Contracts\LinksCarrierReturns;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final class ShippingProviderEventProcessor
{
    public function __construct(
        private readonly CarrierStatusMapper $mapper,
        private readonly ShipmentService $shipments,
        private readonly CarrierGatewayFactory $factories,
        private readonly LinksCarrierReturns $returnLinks,
        private readonly CarrierEvidenceRecorder $evidence,
    ) {}

    public function process(ShippingWebhookEvent $webhook): ShippingProviderEvent
    {
        $existing = ShippingProviderEvent::query()->where('webhook_event_id', $webhook->id)->first();
        if ($existing !== null) {
            return $existing;
        }

        $rawStatus = $this->rawStatusFrom($webhook);
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

        // #181: evidence is additive and must never poison an already-normalized
        // transition or create a replay poison row. Validation failures are
        // reported operationally; the normalized event remains authoritative.
        try {
            $this->evidence->record($webhook, $event->fresh());
        } catch (\Throwable $evidenceFailure) {
            report($evidenceFailure);
        }

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
                'occurred_at' => $webhook->occurred_at,
                'received_at' => $webhook->received_at,
                'ordering_key' => $this->orderingKey($webhook),
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
            $this->raiseException($event, ProviderEventExceptionType::MODE_MISMATCH, $notes);
            return;
        }

        if (! $mapping->canTransition()) {
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
        if ($modeMismatch !== null || ! $mapping->canTransition() || $event->shipment_id === null) {
            return;
        }

        $shipment = Shipment::query()->find((int) $event->shipment_id);
        if ($shipment === null) {
            $event->update(['exception_classification' => ProviderEventExceptionType::UNMATCHED_SHIPMENT->value]);
            $this->raiseException($event, ProviderEventExceptionType::UNMATCHED_SHIPMENT, $this->exceptionNotes($event->raw_status, $mapping->mappingVersion));
            return;
        }

        if ($this->isTerminalReversal($shipment, $mapping)) {
            $event->update(['exception_classification' => ProviderEventExceptionType::TERMINAL_STATE_REVERSAL->value]);
            $this->raiseException(
                $event,
                ProviderEventExceptionType::TERMINAL_STATE_REVERSAL,
                $this->exceptionNotes($event->raw_status, $mapping->mappingVersion, 'terminal_state_reversal:current='.$shipment->status.',mapped='.$mapping->mappedStatus?->value),
            );
            return;
        }

        $orderingBlock = $this->orderingBlockReason($shipment, $event, $mapping);
        if ($orderingBlock !== null) {
            $event->update(['exception_classification' => $orderingBlock->value]);
            $this->raiseException($event, $orderingBlock, $this->exceptionNotes($event->raw_status, $mapping->mappingVersion, 'ordering_block='.$orderingBlock->value));
            return;
        }

        try {
            DB::transaction(function () use ($event, $mapping, $shipment): void {
                $this->shipments->updateStatus(
                    (int) $event->shipment_id,
                    $mapping->mappedStatus->value,
                    'provider_status_map_version:'.$mapping->mappingVersion,
                );

                if ($mapping->mappedStatus === ShipmentStatus::RETURNED) {
                    $this->linkCarrierReturn($event, $shipment->fresh());
                }

                $event->update(['applied_at' => now()]);
            });
        } catch (\RuntimeException $exception) {
            $event->refresh();
            $event->update(['exception_classification' => ProviderEventExceptionType::INVALID_MAPPING_CONFIG->value]);
            $this->raiseException($event, ProviderEventExceptionType::INVALID_MAPPING_CONFIG, $this->exceptionNotes($event->raw_status, $mapping->mappingVersion, $exception->getMessage()));
        }
    }

    /**
     * #174: a returned status links evidence to the existing return request.
     * Nothing is created here; an unsuitable request is skipped, never
     * promoted into a parallel return lifecycle.
     */
    private function linkCarrierReturn(ShippingProviderEvent $event, ?Shipment $shipment): void
    {
        if ($shipment === null) {
            return;
        }

        $parameters = $this->returnLinkParameters($event, $shipment);
        if ($parameters === null) {
            return;
        }

        try {
            $this->returnLinks->linkReturn($parameters);
        } catch (CarrierReturnLinkSkipped) {
            // Canonical shipment status is still authoritative. No suitable
            // return request means no link, not a new return workflow.
        }
    }

    /** @return array<string,mixed>|null */
    private function returnLinkParameters(ShippingProviderEvent $event, Shipment $shipment): ?array
    {
        $eventKey = $event->provider_event_key;
        if (!is_string($eventKey) || trim($eventKey) === '') {
            return null;
        }

        return [
            'shipment_id' => (int) $shipment->id,
            'provider_code' => (string) $event->provider_code,
            'mode' => (string) $event->mode,
            'external_ids' => [
                'shipment_id' => (string) $shipment->id,
                'provider_event_key' => $eventKey,
            ],
            'redacted_payload_reference' => 'shipping_webhook_event:'.$event->id,
            'metadata' => [
                'provider_event_key' => $eventKey,
                'raw_status' => $event->raw_status,
            ],
        ];
    }

    private function raiseException(ShippingProviderEvent $event, ProviderEventExceptionType $type, string $notes): void
    {
        ShipmentException::firstOrCreate(
            ['provider_event_id' => $event->id],
            [
                'shipment_id' => $event->shipment_id,
                'type' => $type->value,
                'severity' => ShipmentExceptionSeverity::HIGH->value,
                'status' => ShipmentExceptionStatus::OPEN->value,
                'notes' => $notes,
                'owner_team' => $this->exceptionOwnerTeam(),
                'sla_due_at' => $this->exceptionSlaDueAt(),
                'opened_at' => now(),
            ],
        );
    }

    /**
     * #191: every fail-closed exception has a named operational owner even
     * before an individual assignee is chosen.
     */
    private function exceptionOwnerTeam(): string
    {
        $owner = config('shipping.exceptions.owner_team');
        $owner = is_string($owner) ? trim($owner) : '';

        return $owner !== '' ? substr($owner, 0, 64) : 'shipping-operations';
    }

    /**
     * #191: an unknown status gets a real response deadline. Business days
     * keep the SLA from expiring over a weekend.
     */
    private function exceptionSlaDueAt(): CarbonImmutable
    {
        $days = max(1, min(30, (int) config('shipping.exceptions.sla_business_days', 1)));
        $due = CarbonImmutable::now();

        while ($days > 0) {
            $due = $due->addDay();
            if (! $due->isWeekend()) {
                $days--;
            }
        }

        return $due;
    }

    private function orderingKey(ShippingWebhookEvent $webhook): string
    {
        $occurred = $webhook->occurred_at?->getTimestamp() ?? 0;
        $received = $webhook->received_at?->getTimestamp() ?? 0;
        return $occurred.':'.$received.':'.$webhook->id;
    }

    private function isTerminalReversal(Shipment $shipment, ProviderStatusMapping $mapping): bool
    {
        $mapped = $mapping->mappedStatus?->value;
        if ($mapped === null) {
            return false;
        }

        if (! in_array((string) $shipment->status, Shipment::TERMINAL_STATUSES, true)) {
            return false;
        }

        return (string) $shipment->status !== $mapped;
    }

    private function orderingBlockReason(Shipment $shipment, ShippingProviderEvent $event, ProviderStatusMapping $mapping): ?ProviderEventExceptionType
    {
        $mapped = $mapping->mappedStatus?->value;
        if ($mapped === null || (string) $shipment->status === $mapped) {
            return null;
        }

        $latestApplied = ShippingProviderEvent::query()
            ->where('shipment_id', $event->shipment_id)
            ->whereNotNull('applied_at')
            ->where('id', '!=', $event->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->first();

        $currentKey = $this->orderingKeyFromEvent($event);

        if ($latestApplied !== null) {
            $latestKey = $this->orderingKeyFromEvent($latestApplied);

            return $currentKey < $latestKey ? ProviderEventExceptionType::OUT_OF_ORDER_EVENT : null;
        }

        $latestTimeline = \App\Models\OrderTrackingEvent::query()
            ->where('order_id', $shipment->order_id)
            ->where('event_type', 'shipment.status_changed')
            ->where('new_status', (string) $shipment->status)
            ->latest('event_timestamp')
            ->latest('id')
            ->first();

        if ($latestTimeline?->event_timestamp !== null) {
            $timelineKey = $latestTimeline->event_timestamp->getTimestamp().':'.$latestTimeline->event_timestamp->getTimestamp().':0';
            if ($currentKey < $timelineKey) {
                return ProviderEventExceptionType::OUT_OF_ORDER_EVENT;
            }
        }

        $baseline = $shipment->updated_at ?? $shipment->created_at;
        if ($baseline !== null) {
            $baselineKey = $baseline->getTimestamp().':'.$baseline->getTimestamp().':0';
            if ($currentKey < $baselineKey) {
                return ProviderEventExceptionType::OUT_OF_ORDER_EVENT;
            }
        }

        if ($event->occurred_at === null || $event->received_at === null) {
            return ProviderEventExceptionType::OUT_OF_ORDER_EVENT;
        }

        return null;
    }

    private function orderingKeyFromEvent(ShippingProviderEvent $event): string
    {
        $occurred = $event->occurred_at?->getTimestamp() ?? 0;
        $received = $event->received_at?->getTimestamp() ?? 0;
        return $occurred.':'.$received.':'.$event->id;
    }

    private function withModeMismatchNote(string $notes, ?string $modeMismatch): string
    {
        return $modeMismatch === null || $modeMismatch === ''
            ? $notes
            : $notes.'; mode_mismatch='.$modeMismatch;
    }

    private function exceptionNotes(?string $rawStatus, string $mappingVersion, ?string $transitionError = null): string
    {
        $notes = 'raw_status='.($rawStatus ?? '<missing>').'; mapping_version='.($mappingVersion !== '' ? $mappingVersion : '<none>');
        if ($transitionError !== null && $transitionError !== '') {
            $notes .= '; transition_error='.$transitionError;
        }

        return $notes;
    }
}
