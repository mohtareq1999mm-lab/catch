<?php

declare(strict_types=1);

namespace App\DTOs\Tax;

/**
 * Computed tax amounts for checkout, in CATALOG currency.
 * product_taxable = sum of taxableLineBase (after discounts, before tax)
 * order_taxable = same sum (explicitly NOT including product tax or shipping)
 */
class TaxBreakdown
{
    public function __construct(
        public readonly float $productTaxableAmount = 0.0,
        public readonly float $productTaxAmount = 0.0,
        public readonly ?float $orderTaxRate = null,
        public readonly float $orderTaxableAmount = 0.0,
        public readonly float $orderTaxAmount = 0.0,
        public readonly array $lineTaxes = [], // [cartItemId => ['taxable'=>float,'rate'=>float,'amount'=>float]]
        public readonly float $taxableBase = 0.0, // alias for orderTaxableAmount (BC)
    ) {}

    public function totalTaxAmount(): float
    {
        return round($this->productTaxAmount + $this->orderTaxAmount, 2);
    }
}
