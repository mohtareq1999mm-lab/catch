<?php

declare(strict_types=1);

namespace App\Services\Shipment;

use App\DTOs\Payment\CollectibleCodAmount;
use App\DTOs\Shipping\CarrierCodEvidence;
use App\DTOs\Shipping\CarrierMoney;
use App\Exceptions\CodAmountEvidenceException;
use App\Models\Shipment;
use App\Models\Shipping\ShipmentDeliveryAttempt;
use App\Models\Shipping\ShipmentPod;
use App\Services\Payment\OrderPaidAmountService;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Marvel\Database\Models\Order;

/**
 * #181: append-only cash-collection evidence on delivery records.
 *
 * Carrier evidence is checked against the order, the authoritative COD
 * snapshot, and the shipment's provider identity before it is written. It is
 * NOT a payment snapshot: this service never touches transactions, order
 * status, fulfillment, inventory, or the manual mark-paid completion authority.
 */
final class CodCashEvidenceService
{
    public const METADATA_KEY = 'cash_collected_evidence';

    /** @var list<string> */
    private const SAFE_EXTERNAL_IDS = [
        'order_id',
        'shipment_id',
        'delivery_attempt_id',
        'pod_id',
        'reference',
        'tracking_number',
        'awb',
        'waybill',
    ];

    public function __construct(private readonly OrderPaidAmountService $amounts) {}

    public function recordOnAttempt(
        ShipmentDeliveryAttempt $attempt,
        CarrierCodEvidence $evidence,
    ): ShipmentDeliveryAttempt {
        $attempt->loadMissing('shipment.order');
        $shipment = $attempt->shipment;
        if (!$shipment instanceof Shipment) {
            throw new InvalidArgumentException('Delivery attempt requires a shipment.');
        }

        $payload = $this->validatedPayload($shipment, $evidence, [
            'order_id' => (int) $shipment->order_id,
            'delivery_attempt_id' => (int) $attempt->getKey(),
        ]);
        $this->mergeMetadata($attempt, $payload);

        return $attempt->fresh();
    }

    public function recordOnPod(ShipmentPod $pod, CarrierCodEvidence $evidence): ShipmentPod
    {
        $pod->loadMissing('shipment.order');
        $shipment = $pod->shipment;
        if (!$shipment instanceof Shipment) {
            throw new InvalidArgumentException('Proof of delivery requires a shipment.');
        }

        $this->assertPodAttempt($pod, $shipment);
        $payload = $this->validatedPayload($shipment, $evidence, [
            'order_id' => (int) $shipment->order_id,
            'pod_id' => (int) $pod->getKey(),
            'delivery_attempt_id' => $pod->shipment_delivery_attempt_id === null
                ? null
                : (int) $pod->shipment_delivery_attempt_id,
        ]);
        $this->mergeMetadata($pod, $payload);

        return $pod->fresh();
    }

    /**
     * @param array<string,int|null> $expectedIdentities
     * @return array<string,mixed>
     */
    private function validatedPayload(
        Shipment $shipment,
        CarrierCodEvidence $evidence,
        array $expectedIdentities,
    ): array {
        $order = $this->codOrder($shipment);
        $this->assertProviderIdentity($shipment, $evidence);
        $this->assertEvidenceIdentity($evidence, (int) $order->getKey(), (int) $shipment->getKey(), $expectedIdentities);

        $amount = $this->validatedAmount($evidence);
        $snapshot = $this->amounts->forOrder($order);
        $this->assertSnapshotMatches($amount, $snapshot);

        return $this->payload($evidence, $amount, $snapshot);
    }

    private function codOrder(Shipment $shipment): Order
    {
        $order = $shipment->order;
        if (!$order instanceof Order || !$order->exists) {
            throw CodAmountEvidenceException::missingOrder();
        }
        if (strtolower(trim((string) $order->payment_method)) !== 'cod') {
            throw new InvalidArgumentException('Cash evidence requires a COD order.');
        }

        return $order;
    }

    private function assertProviderIdentity(Shipment $shipment, CarrierCodEvidence $evidence): void
    {
        if ($shipment->provider_code !== $evidence->providerCode
            || $shipment->provider_mode !== $evidence->mode
        ) {
            throw new InvalidArgumentException('Cash evidence provider or mode does not match the shipment.');
        }
    }

    private function assertPodAttempt(ShipmentPod $pod, Shipment $shipment): void
    {
        $attemptId = $pod->shipment_delivery_attempt_id;
        if ($attemptId === null) {
            throw new InvalidArgumentException('Proof of delivery requires a delivery attempt.');
        }

        $attempt = ShipmentDeliveryAttempt::query()->find((int) $attemptId);
        if ($attempt === null || (int) $attempt->shipment_id !== (int) $shipment->getKey()) {
            throw new InvalidArgumentException('Delivery attempt does not belong to the POD shipment.');
        }
    }

    private function validatedAmount(CarrierCodEvidence $evidence): CarrierMoney
    {
        $amount = $evidence->amount;
        if ($amount === null || $amount->amountMinor <= 0) {
            throw CodAmountEvidenceException::invalidAmount();
        }

        return $amount;
    }

    private function assertSnapshotMatches(CarrierMoney $amount, CollectibleCodAmount $snapshot): void
    {
        if (strtoupper($amount->currency) !== $snapshot->currencyCode) {
            throw CodAmountEvidenceException::mixedCurrency();
        }
        if ($amount->amountMinor !== $snapshot->collectibleMinor) {
            throw CodAmountEvidenceException::inconsistentEvidence();
        }
    }

    /** @return array<string,mixed> */
    private function payload(
        CarrierCodEvidence $evidence,
        CarrierMoney $amount,
        CollectibleCodAmount $snapshot,
    ): array {
        return [
            'source' => 'carrier_cod_evidence',
            'provider_code' => $evidence->providerCode,
            'mode' => $evidence->mode,
            'external_ids' => $this->safeExternalIds($evidence->externalIds),
            'amount_minor' => $amount->amountMinor,
            'currency_code' => $snapshot->currencyCode,
            'redacted_payload_reference' => $evidence->redactedPayloadReference,
            'actor_type' => $evidence->actorType,
            'actor_id' => $evidence->actorId,
            'recorded_at' => $evidence->recordedAt?->toIso8601String(),
            'validated_against' => 'order_cod_snapshot',
        ];
    }

    /** @param array<string,int|null> $expectedIdentities */
    private function assertEvidenceIdentity(
        CarrierCodEvidence $evidence,
        int $orderId,
        int $shipmentId,
        array $expectedIdentities,
    ): void {
        $hasOrder = array_key_exists('order_id', $evidence->externalIds);
        $hasShipment = array_key_exists('shipment_id', $evidence->externalIds);
        if (!$hasOrder && !$hasShipment) {
            throw new InvalidArgumentException('Carrier cash evidence must identify an order or shipment.');
        }

        $expected = ['order_id' => $orderId, 'shipment_id' => $shipmentId] + $expectedIdentities;
        foreach ($expected as $key => $value) {
            if ($value !== null) {
                $this->assertMatchingIdentity($evidence, $key, $value);
            }
        }
    }

    private function assertMatchingIdentity(CarrierCodEvidence $evidence, string $key, int $expected): void
    {
        $presented = $evidence->externalIds[$key] ?? null;
        if ($presented === null) {
            return;
        }

        if (preg_match('/^[1-9][0-9]*$/', $presented) !== 1 || (int) $presented !== $expected) {
            throw new InvalidArgumentException("Carrier cash evidence {$key} does not match the shipment.");
        }
    }

    /**
     * Lock the owning row; compare the complete allowlisted payload so a
     * different reference cannot masquerade as an idempotent retry.
     *
     * @param array<string,mixed> $payload
     */
    private function mergeMetadata(Model $model, array $payload): void
    {
        DB::transaction(function () use ($model, $payload): void {
            /** @var Model $locked */
            $locked = $model->newQuery()->whereKey($model->getKey())->lockForUpdate()->firstOrFail();
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $existing = $metadata[self::METADATA_KEY] ?? null;

            if (is_array($existing)) {
                if ($existing === $payload) {
                    return;
                }

                throw new DomainException('Conflicting cash evidence cannot overwrite recorded evidence.');
            }

            $metadata[self::METADATA_KEY] = $payload;
            $locked->forceFill(['metadata' => $metadata])->save();
        });
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
