<?php

declare(strict_types=1);

namespace App\Services\Tax;

use Marvel\Database\Models\Product;

/**
 * Product-level tax presentation. Direct rate fields, zero queries.
 */
class ProductTaxPresenter
{
    /**
     * Tax-inclusive catalog-currency price for an effective product price.
     */
    public function applyTo(Product $product, float $effectivePrice): float
    {
        if (empty($product->tax_enabled) || $effectivePrice <= 0) {
            return round(max(0.0, $effectivePrice), 2);
        }
        $rate = (float) ($product->tax_rate ?? 0);
        if ($rate <= 0) {
            return round($effectivePrice, 2);
        }
        $taxCents = TaxCalculator::amountOn(TaxCalculator::toCents($effectivePrice), $rate);
        return TaxCalculator::fromCents(TaxCalculator::toCents($effectivePrice) + $taxCents);
    }

    /**
     * Admin breakdown for a given effective (pre-tax) price.
     *
     * @return array{tax_enabled: bool, tax_rate: ?float, amount: float}|null
     */
    public function describe(Product $product, float $effectivePrice): ?array
    {
        if (empty($product->tax_enabled) && $product->tax_rate === null) {
            // No tax configured at all -> null for cleaner admin empty state
            // But we still want to show disabled state when rate exists?
            // Spec: when disabled, show tax_enabled=false with amount 0
            // So we return data even when disabled if rate is not null.
            if ($product->tax_rate === null) {
                return null;
            }
        }

        // If tax_enabled is false but rate exists, we still return breakdown with amount 0
        // If tax_enabled true, amount is calculated
        $rate = $product->tax_rate !== null ? (float) $product->tax_rate : null;
        $enabled = (bool) $product->tax_enabled;
        $amount = 0.0;
        if ($enabled && $rate !== null && $rate > 0 && $effectivePrice > 0) {
            $amount = TaxCalculator::fromCents(TaxCalculator::amountOn(TaxCalculator::toCents($effectivePrice), $rate));
        }

        // When product has no tax configuration at all (enabled false and rate null), return null
        if (!$enabled && $rate === null) {
            return null;
        }

        return [
            'tax_enabled' => $enabled,
            'tax_rate' => $rate,
            'amount' => $amount,
        ];
    }
}
