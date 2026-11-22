<?php

declare(strict_types=1);

namespace App\DTOs\Shipping;

use App\Enums\Shipping\CarrierCapability;
use InvalidArgumentException;

/**
 * Non-secret resolution result handed to a carrier adapter.
 * Credentials/webhook secrets deliberately stay in deployment config.
 */
final class CarrierGatewayContext
{
    /** @param list<string> $verifiedCapabilities */
    public function __construct(
        public readonly string $providerCode,
        public readonly string $mode,
        public readonly string $accountCode,
        public readonly string $channel,
        public readonly string $geography,
        public readonly string $baseUrl,
        public readonly int $timeoutSeconds,
        public readonly int $retryAttempts,
        public readonly int $retryBackoffMs,
        public readonly int $retryMaxBackoffMs,
        public readonly string $dispatchQueue,
        public readonly string $webhookQueue,
        public readonly string $webhookVersion,
        public readonly int $statusMapVersion,
        public readonly array $verifiedCapabilities,
    ) {
        foreach ([$providerCode, $mode, $accountCode, $channel, $geography, $baseUrl, $dispatchQueue, $webhookQueue, $webhookVersion] as $value) {
            if (trim($value) === '') {
                throw new InvalidArgumentException('Carrier context requires non-empty identity, routing and version values.');
            }
        }
        if ($timeoutSeconds < 1 || $retryAttempts < 1 || $retryAttempts > 5
            || $retryBackoffMs < 0 || $retryBackoffMs > 5000
            || $retryMaxBackoffMs < $retryBackoffMs || $retryMaxBackoffMs > 30000
        ) {
            throw new InvalidArgumentException('Carrier context has out-of-bounds timeout or retry settings.');
        }
        foreach ($verifiedCapabilities as $capability) {
            if (!is_string($capability) || CarrierCapability::tryFrom($capability) === null) {
                throw new InvalidArgumentException('Carrier context contains an unknown capability.');
            }
        }
    }
}
