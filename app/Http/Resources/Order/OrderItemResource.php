<?php

namespace App\Http\Resources\Order;

use App\Http\Resources\Product\ProductMiniResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'quantity' => (int) $this->product_quantity,
            'unit_price' => $this->roundMoney($this->product_price),
            'total_price' => $this->roundMoney($this->product_total_price),
            'converted_unit_price' => $this->convertWithOrderRate($this->product_price),
            'converted_total_price' => $this->convertWithOrderRate($this->product_total_price),
            'promotion_discount_amount' => $this->roundMoney($this->promotion_discount_amount),
            'is_gift' => (bool) ($this->is_gift ?? false),
            // Historical snapshot — never resolved from the current Product.
            'item_type' => $this->item_type ?? \Marvel\Enums\ItemType::PHYSICAL,
            'promotion_id' => $this->promotion_id,
            'product' => $this->resolveProduct($request),
            'variant' => $this->resolveVariant($request),
        ];
    }

    /**
     * @return string|null
     */
    private function orderRate(): ?string
    {
        if (!$this->relationLoaded('order') || !$this->order) {
            return null;
        }

        $rate = $this->order->currency_rate;

        if ($rate === null || $rate === '') {
            return null;
        }

        return (string) $rate;
    }

    private function convertWithOrderRate($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        $amount = (float) $value;
        $rate = $this->orderRate();

        if ($rate === null || (float) $rate === 1.0) {
            return round($amount, 2);
        }

        return round($amount * (float) $rate, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveProduct(Request $request): array
    {
        if ($this->relationLoaded('product') && $this->product) {
            return ProductMiniResource::make($this->product)->toArray($request);
        }

        return [
            'id' => $this->product_id,
            'name' => $this->product_name,
            'sku' => $this->product_sku,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function resolveVariant(Request $request): ?array
    {
        if (!$this->product_variant_id) {
            return null;
        }

        if ($this->relationLoaded('productVariant') && $this->productVariant) {
            return OrderProductVariantResource::make($this->productVariant)->toArray($request);
        }

        return [
            'id' => $this->product_variant_id,
            'attributes' => $this->attributes,
        ];
    }

    private function roundMoney(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return round((float) $value, 2);
    }
}
