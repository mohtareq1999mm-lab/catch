<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * #143: normalized, authenticated carrier delivery.
 *
 * Only redacted identity fields are carried. Bounded AWB/order-number identity
 * is included for deterministic matching; the raw body and the presented
 * signature never enter this DTO, the inbox row, or application logs.
 */
final class CarrierWebhookEvent extends CarrierResult
{
    /** @param array<string,mixed> $redactedPayload */
    public function __construct(
        string $providerCode,
        string $mode,
        array $externalIds,
        ?CarrierMoney $amount,
        string $redactedPayloadReference,
        public readonly string $eventKey,
        public readonly string $eventType,
        public readonly string $webhookVersion,
        public readonly string $accountCode,
        public readonly ?string $providerShipmentRef,
        public readonly ?CarbonImmutable $occurredAt,
        public readonly array $redactedPayload,
        public readonly ?string $shipmentUuid = null,
        public readonly ?string $rawStatus = null,
        public readonly ?string $awb = null,
        public readonly ?string $orderNumber = null,
    ) {
        parent::__construct($providerCode, $mode, $externalIds, $amount, $redactedPayloadReference);

        if (trim($eventKey) === '' || strlen($eventKey) > 191) {
            throw new InvalidArgumentException('Carrier webhook event key must be a non-empty bounded string.');
        }
        if (trim($eventType) === '' || strlen($eventType) > 64) {
            throw new InvalidArgumentException('Carrier webhook event type must be a non-empty bounded string.');
        }
        if (trim($webhookVersion) === '') {
            throw new InvalidArgumentException('Carrier webhook version is required.');
        }
        if (trim($accountCode) === '' || strlen($accountCode) > 64) {
            throw new InvalidArgumentException('Carrier webhook account identity is required.');
        }
        if ($providerShipmentRef !== null && strlen($providerShipmentRef) > 191) {
            throw new InvalidArgumentException('Carrier webhook shipment reference is too long.');
        }
        if ($rawStatus !== null && strlen($rawStatus) > 191) {
            throw new InvalidArgumentException('Carrier webhook raw status is too long.');
        }
        foreach (['awb' => $awb, 'order number' => $orderNumber] as $label => $identity) {
            if ($identity !== null && (trim($identity) === '' || strlen($identity) > 191)) {
                throw new InvalidArgumentException("Carrier webhook {$label} identity must be a bounded non-empty string.");
            }
        }
        foreach ($redactedPayload as $name => $value) {
            if (!is_string($name) || trim($name) === '' || (!is_scalar($value) && $value !== null)) {
                throw new InvalidArgumentException('Redacted carrier payload must contain only scalar fields.');
            }
        }
    }
}
