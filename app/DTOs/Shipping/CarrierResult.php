<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

use InvalidArgumentException;

/**
 * Shared metadata for every provider result DTO.
 *
 * The raw provider payload is never carried: only a redacted reference that
 * can be resolved by the private audit store.
 */
abstract class CarrierResult
{
    /** @param array<string,string> $externalIds */
    public function __construct(
        public readonly string $providerCode,
        public readonly string $mode,
        public readonly array $externalIds,
        public readonly ?CarrierMoney $amount,
        public readonly string $redactedPayloadReference,
    ) {
        if (trim($providerCode) === '' || trim($mode) === '') {
            throw new InvalidArgumentException('Carrier result requires a provider code and mode.');
        }
        if ($externalIds === []) {
            throw new InvalidArgumentException('Carrier result requires at least one external identifier.');
        }
        foreach ($externalIds as $name => $value) {
            if (!is_string($name) || trim($name) === '' || !is_string($value) || trim($value) === '') {
                throw new InvalidArgumentException('Carrier external identifiers must be non-empty strings.');
            }
        }
        if (trim($redactedPayloadReference) === '') {
            throw new InvalidArgumentException('Carrier result requires a redacted payload reference.');
        }
    }
}
