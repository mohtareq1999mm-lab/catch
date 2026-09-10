<?php

namespace App\DTOs;

use App\DTOs\Tax\TaxBreakdown;

class CheckoutTotals
{
    public function __construct(
        public readonly float $subtotal,
        public readonly float $promotionDiscount,
        public readonly float $couponDiscount,
        public readonly float $finalTotal,
        public readonly ?array $promotion = null,
        public readonly array $giftItems = [],
        public readonly ?string $coupon = null,
        public readonly ?string $couponDiscountType = null,
        public readonly ?float $couponDiscountMaxAmount = null,
        public readonly string $currency = 'EGP',
        public readonly ?TaxBreakdown $tax = null,
    ) {}

    public function withTax(?TaxBreakdown $tax): self
    {
        return new self(
            subtotal: $this->subtotal,
            promotionDiscount: $this->promotionDiscount,
            couponDiscount: $this->couponDiscount,
            finalTotal: $this->finalTotal,
            promotion: $this->promotion,
            giftItems: $this->giftItems,
            coupon: $this->coupon,
            couponDiscountType: $this->couponDiscountType,
            couponDiscountMaxAmount: $this->couponDiscountMaxAmount,
            currency: $this->currency,
            tax: $tax,
        );
    }

    public function productTaxAmount(): float
    {
        return round($this->tax->productTaxAmount ?? 0.0, 2);
    }

    public function orderTaxAmount(): float
    {
        return round($this->tax->orderTaxAmount ?? 0.0, 2);
    }

    public function totalTaxAmount(): float
    {
        return round($this->productTaxAmount() + $this->orderTaxAmount(), 2);
    }

    public function hasTax(): bool
    {
        return $this->tax !== null;
    }

    public function getTotalDiscount(): float
    {
        return round($this->promotionDiscount + $this->couponDiscount, 2);
    }

    public function hasCoupon(): bool
    {
        return $this->coupon !== null;
    }

    public function hasPromotion(): bool
    {
        return $this->promotion !== null;
    }

    public function promotionId(): ?int
    {
        return $this->promotion['id'] ?? null;
    }

    public function promotionCode(): ?string
    {
        return $this->promotion['code'] ?? null;
    }

    public function promotionType(): ?string
    {
        return $this->promotion['type'] ?? null;
    }

    public function toArray(): array
    {
        return [
            'subtotal' => $this->subtotal,
            'promotion_discount' => $this->promotionDiscount,
            'coupon_discount' => $this->couponDiscount,
            'final_total' => $this->finalTotal,
            'promotion' => $this->promotion,
            'gift_items' => $this->giftItems,
            'coupon' => $this->coupon,
            'coupon_discount_type' => $this->couponDiscountType,
            'coupon_discount_max_amount' => $this->couponDiscountMaxAmount,
            'tax' => $this->tax === null ? null : [
                'product_taxable_amount' => $this->tax->productTaxableAmount,
                'product_tax_amount' => $this->tax->productTaxAmount,
                'order_tax_rate' => $this->tax->orderTaxRate,
                'order_taxable_amount' => $this->tax->orderTaxableAmount,
                'order_tax_amount' => $this->tax->orderTaxAmount,
                'taxable_base' => $this->tax->taxableBase,
                'line_taxes' => $this->tax->lineTaxes,
            ],
        ];
    }

    public static function fromPromotionService(array $promotionTotals, float $couponDiscount = 0, ?string $coupon = null, ?string $couponDiscountType = null, ?float $couponDiscountMaxAmount = null): self
    {
        return new self(
            subtotal: (float) ($promotionTotals['subtotal'] ?? 0),
            promotionDiscount: (float) ($promotionTotals['discount'] ?? 0),
            couponDiscount: $couponDiscount,
            finalTotal: (float) ($promotionTotals['final_total'] ?? 0),
            promotion: $promotionTotals['promotion'] ?? null,
            giftItems: $promotionTotals['gift_items'] ?? [],
            coupon: $coupon,
            couponDiscountType: $couponDiscountType,
            couponDiscountMaxAmount: $couponDiscountMaxAmount,
        );
    }
}
