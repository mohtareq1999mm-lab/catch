<?php

namespace App\Contracts;

use App\DTOs\ExchangeRateSnapshot;

interface ExchangeRateProviderInterface
{
    /**
     * @param array<int, string> $quotes
     */
    public function getLatestRates(string $baseCurrency, array $quotes = []): ExchangeRateSnapshot;

    public function name(): string;
}
