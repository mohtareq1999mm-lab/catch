<?php

namespace App\DTOs;

class CurrencyConversionResult
{
    public function __construct(
        public readonly float $amount,
        public readonly float $convertedAmount,
        public readonly string $rate,
        public readonly string $effectiveDate,
        public readonly string $fromCode,
        public readonly string $toCode,
        public readonly string $sourceRate,
        public readonly string $targetRate,
    ) {
    }
}
