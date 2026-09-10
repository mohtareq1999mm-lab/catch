<?php

declare(strict_types=1);

namespace App\Services\Tax;

/**
 * Pure tax arithmetic shared by storefront presentation, checkout and the
 * order override recalculation. This is the ONLY place tax math lives.
 *
 * Rules:
 *  - integer cents internally, deterministic rounding
 *  - largest-remainder allocation for per-line amounts (no rounding drift)
 *  - negative bases/rates are clamped, never thrown — a tax is never negative
 */
class TaxCalculator
{
    public static function toCents(float|string|int|null $amount): int
    {
        if ($amount === null || $amount === '') {
            return 0;
        }

        return (int) round(max(0.0, (float) $amount) * 100);
    }

    public static function fromCents(int $cents): float
    {
        return round(max(0, $cents) / 100, 2);
    }

    public static function normalizeRate(float|string|int|null $rate): float
    {
        return max(0.0, min(100.0, (float) ($rate ?? 0)));
    }

    /** Tax cents on a single base. */
    public static function amountOn(int $baseCents, float $rate): int
    {
        $rate = self::normalizeRate($rate);

        if ($baseCents <= 0 || $rate <= 0.0) {
            return 0;
        }

        return (int) round($baseCents * $rate / 100);
    }

    /**
     * Proportionally allocate $totalCents across the given weights using the
     * largest-remainder method. The allocated cents always sum to $totalCents.
     *
     * @param array<string|int, int> $weightsCents
     * @return array<string|int, int>
     */
    public static function allocate(array $weightsCents, int $totalCents): array
    {
        $weightsCents = array_map(fn ($w) => max(0, (int) $w), $weightsCents);
        $totalWeight = array_sum($weightsCents);

        $result = array_fill_keys(array_keys($weightsCents), 0);

        if ($totalWeight <= 0 || $totalCents <= 0) {
            return $result;
        }

        $totalCents = min($totalCents, $totalWeight);
        $floors = [];
        $remainders = [];

        foreach ($weightsCents as $key => $weight) {
            $exact = $totalCents * $weight / $totalWeight;
            $floors[$key] = (int) floor($exact);
            $remainders[$key] = $exact - $floors[$key];
        }

        $left = $totalCents - array_sum($floors);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $floors[$key]++;
            $left--;
        }

        return $floors;
    }

    /**
     * Per-line tax for ONE rate group. Guarantees that the allocated line
     * taxes sum exactly to round(sum(line nets) × rate / 100) — the same
     * largest-remainder reconciliation the promotion engine uses.
     *
     * @param array<string|int, int> $lineNetCents
     * @return array<string|int, int>
     */
    public static function groupLineTaxes(array $lineNetCents, float $rate): array
    {
        $rate = self::normalizeRate($rate);

        if ($rate <= 0.0) {
            return array_fill_keys(array_keys($lineNetCents), 0);
        }

        $lineNetCents = array_map(fn ($n) => max(0, (int) $n), $lineNetCents);
        $exact = [];
        $floors = [];
        $remainders = [];
        $exactTotal = 0.0;

        foreach ($lineNetCents as $key => $net) {
            $share = $net * $rate / 100;
            $exact[$key] = $share;
            $floors[$key] = (int) floor($share);
            $remainders[$key] = $share - $floors[$key];
            $exactTotal += $share;
        }

        $intended = (int) round($exactTotal);
        $left = $intended - array_sum($floors);
        arsort($remainders);

        foreach (array_keys($remainders) as $key) {
            if ($left <= 0) {
                break;
            }
            $floors[$key]++;
            $left--;
        }

        return $floors;
    }
}
