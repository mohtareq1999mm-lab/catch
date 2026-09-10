<?php

namespace App\DTOs;

use Carbon\CarbonImmutable;

final class ExchangeRateSnapshot
{
    /**
     * @param array<string, string> $rates
     */
    public function __construct(
        public readonly string $provider,
        public readonly string $providerBase,
        public readonly string $anchor,
        public readonly ?CarbonImmutable $providerRateAt,
        public readonly array $rates,
    ) {
    }
}
