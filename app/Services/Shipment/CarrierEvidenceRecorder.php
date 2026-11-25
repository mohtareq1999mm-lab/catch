<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Shipping\CarrierCodEvidence;
use App\DTOs\Shipping\CarrierMoney;
use App\DTOs\Shipping\CarrierReturnLink;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentPod;
use App\Models\Shipping\ShippingProviderEvent;
use App\Models\Shipping\ShippingWebhookEvent;
use App\Services\Fulfillment\GuestCarrierReturnService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * #181: application seam between normalized provider events and the two
 * evidence-only services. It never creates a return request, mutates payment,
 * order, fulfillment, or inventory state, or dispatches an event.
 */
final class CarrierEvidenceRecorder
{
    /** @var list<string> */
    private const EVIDENCE_IDS = [
        'order_id',
        'shipment_id',
        'delivery_attempt_id',
        'pod_id',
        'reference',
        'tracking_number',
        'awb',
        'waybill',
    ];

    public function __construct(
        private readonly GuestCarrierReturnService $guestReturns,
        private readonly CodCashEvidenceService $cashEvidence,
    ) {}

    public function record(ShippingWebhookEvent $webhook, ShippingProviderEvent $event): void
    {
        if ($event->shipment_id === null) {
            return;
        }

        $shipment = Shipment::query()->find((int) $event->shipment_id);
        if (!$shipment instanceof Shipment || !$this->matchesProviderIdentity($shipment, $webhook, $event)) {
            return;
        }

        $this->recordReturn($webhook, $event, $shipment);
        $this->recordCash($webhook, $event, $shipment);
    }

    private function matchesProviderIdentity(
        Shipment $shipment,
        ShippingWebhookEvent $webhook,
        ShippingProviderEvent $event,
    ): bool {
        if ($event->provider_code !== $webhook->provider_code || $event->mode !== $webhook->mode) {
            return false;
        }

        return $shipment->provider_code === $webhook->provider_code
            && $shipment->provider_mode === $webhook->mode;
    }

    private function recordReturn(
        ShippingWebhookEvent $webhook,
        ShippingProviderEvent $event,
        Shipment $shipment,
    ): void {
        if (!$this->isReturnEvent($webhook)) {
            return;
        }

        $ids = $this->externalIds($webhook, $shipment);
        $ids['return_reference'] = $event->provider_event_key;
        if ($webhook->provider_shipment_ref !== null) {
            $ids['carrier_reference'] = $webhook->provider_shipment_ref;
        }

        try {
            $this->guestReturns->record(
                $shipment,
                new CarrierReturnLink(
                    (string) $webhook->provider_code,
                    (string) $webhook->mode,
                    $ids,
                    null,
                    $event->payload_reference,
                ),
                $event,
            );
        } catch (InvalidArgumentException) {
            // Registered orders and mismatched identities are not guest
            // returns; the event itself remains in the evidence inbox.
        }
    }

    private function recordCash(
        ShippingWebhookEvent $webhook,
        ShippingProviderEvent $event,
        Shipment $shipment,
    ): void {
        $payload = is_array($webhook->redacted_payload) ? $webhook->redacted_payload : [];
        $money = $this->moneyFrom($payload);
        if ($money === null || !$this->isDeliveryEvent($webhook)) {
            return;
        }

        $evidence = new CarrierCodEvidence(
            (string) $webhook->provider_code,
            (string) $webhook->mode,
            $this->externalIds($webhook, $shipment),
            $money,
            $event->payload_reference,
            $this->scalar($payload, 'actor_type') ?? 'carrier',
            $this->scalar($payload, 'actor_id') ?? $event->provider_event_key,
            $webhook->occurred_at === null ? null : CarbonImmutable::instance($webhook->occurred_at),
        );

        $attemptId = $this->positiveId($payload['delivery_attempt_id'] ?? null);
        if ($attemptId !== null) {
            $attempt = ShipmentDeliveryAttempt::query()
                ->whereKey($attemptId)
                ->where('shipment_id', $shipment->id)
                ->first();
            if ($attempt instanceof ShipmentDeliveryAttempt) {
                $this->cashEvidence->recordOnAttempt($attempt, $evidence);
            }
        }

        $podId = $this->positiveId($payload['pod_id'] ?? null);
        if ($podId !== null) {
            $pod = ShipmentPod::query()
                ->whereKey($podId)
                ->where('shipment_id', $shipment->id)
                ->first();
            if ($pod instanceof ShipmentPod) {
                $this->cashEvidence->recordOnPod($pod, $evidence);
            }
        }
    }

    /** @param array<string,mixed> $payload */
    private function moneyFrom(array $payload): ?CarrierMoney
    {
        $minor = $this->scalar($payload, 'cash_collected_minor');
        $currency = $this->scalar($payload, 'currency_code');
        if ($minor === null || $currency === null || strlen($minor) > 18
            || preg_match('/^[1-9][0-9]*$/', $minor) !== 1
            || preg_match('/^[A-Z]{3}$/', $currency) !== 1
        ) {
            return null;
        }

        return new CarrierMoney((int) $minor, $currency);
    }

    /** @return array<string,string> */
    private function externalIds(ShippingWebhookEvent $webhook, Shipment $shipment): array
    {
        $payload = is_array($webhook->redacted_payload) ? $webhook->redacted_payload : [];
        $ids = [];
        foreach (self::EVIDENCE_IDS as $key) {
            $value = $this->scalar($payload, $key);
            if ($value !== null) {
                $ids[$key] = $value;
            }
        }

        $ids['order_id'] = (string) $shipment->order_id;
        $ids['shipment_id'] = (string) $shipment->getKey();

        return $ids;
    }

    /** @param array<string,mixed> $payload */
    private function scalar(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        return is_string($value) && trim($value) !== '' && strlen($value) <= 191 ? $value : null;
    }

    private function positiveId(mixed $value): ?int
    {
        if (!is_string($value) || preg_match('/^[1-9][0-9]*$/', $value) !== 1 || strlen($value) > 18) {
            return null;
        }

        return (int) $value;
    }

    private function isReturnEvent(ShippingWebhookEvent $webhook): bool
    {
        return $this->contains($webhook->event_type, 'return')
            || $this->contains($webhook->raw_status, 'return');
    }

    private function isDeliveryEvent(ShippingWebhookEvent $webhook): bool
    {
        return $this->contains($webhook->event_type, 'delivery')
            || $this->contains($webhook->event_type, 'cod')
            || $this->contains($webhook->raw_status, 'delivered');
    }

    private function contains(?string $haystack, string $needle): bool
    {
        return $haystack !== null && str_contains(strtolower($haystack), $needle);
    }
}
