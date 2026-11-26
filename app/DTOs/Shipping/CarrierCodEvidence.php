<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

final class CarrierCodEvidence extends CarrierResult
{
    public function __construct(
        string $providerCode,
        string $mode,
        array $externalIds,
        ?CarrierMoney $amount,
        string $redactedPayloadReference,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null,
        public readonly ?CarbonImmutable $recordedAt = null,
    ) {
        parent::__construct($providerCode, $mode, $externalIds, $amount, $redactedPayloadReference);

        foreach (['actor_type' => $actorType, 'actor_id' => $actorId] as $field => $value) {
            if ($value !== null && (trim($value) === '' || strlen($value) > 64)) {
                throw new InvalidArgumentException("Carrier cash evidence {$field} must be a bounded string.");
            }
        }
    }
}
