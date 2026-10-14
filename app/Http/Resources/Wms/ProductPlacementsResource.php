<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Product with all its location placements (multi-location read model).
 *
 * Central stock is SKU-level: simple → products.stock_quantity,
 * variant → product_variants.stock_quantity (variants own stock, parents
 * with variants are NOT summed to avoid double-counting).
 * Placements are non-authoritative hints — their sum is informational and
 * is NEVER written back to stock. allocated_hint is informational only
 * (pending allocation suggestion), NOT authoritative reservation data
 * (reservations live on the authority row via OrderReservationService).
 */
class ProductPlacementsResource extends JsonResource
{
    /**
     * @param Request $request
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \Marvel\Database\Models\Product $product */
        $product = $this->resource['product'];
        $placements = $this->resource['placements'] ?? collect();

        $stock = (int) ($product->stock_quantity ?? 0);
        $reserved = (int) ($product->reserved_quantity ?? 0);

        $variants = $product->relationLoaded('variations')
            ? $product->variations->map(fn ($v) => [
                'id' => $v->id,
                'sku' => $v->sku ?? null,
                'stock_quantity' => (int) ($v->stock_quantity ?? 0),
                'reserved_quantity' => (int) ($v->reserved_quantity ?? 0),
                'available_quantity' => max(0, (int) ($v->stock_quantity ?? 0) - (int) ($v->reserved_quantity ?? 0)),
                'in_stock' => (bool) ($v->in_stock ?? ((int) ($v->stock_quantity ?? 0) > 0)),
                'attributes' => $this->variantAttributes($v),
            ])->all()
            : null;

        return [
            'product' => [
                'id' => $product->id,
                'sku' => $product->sku ?? null,
                'product_type' => $product->product_type ?? null,
                'has_variants' => $variants !== null ? count($variants) > 0 : (bool) $product->variations()->exists(),
                'stock_quantity' => $stock,
                'reserved_quantity' => $reserved,
                'available_quantity' => max(0, $stock - $reserved),
                'in_stock' => (bool) ($product->in_stock ?? $stock > 0),
                'variants' => $variants,
            ],
            'placements' => collect($placements)->map(fn ($p) => [
                'id' => $p->id,
                'product_id' => $p->product_id,
                'product_variant_id' => $p->product_variant_id !== null ? (int) $p->product_variant_id : null,
                'sku' => $p->relationLoaded('variant') && $p->variant ? ($p->variant->sku ?? null) : ($product->sku ?? null),
                'variant_attributes' => $p->relationLoaded('variant') && $p->variant ? $this->variantAttributes($p->variant) : null,
                'location_id' => $p->location_id,
                'location_code' => $p->relationLoaded('location') ? $p->location?->code : null,
                'warehouse_id' => $p->warehouse_id,
                'quantity' => (float) $p->quantity,
                // Informational only — NOT authoritative reservation data.
                'allocated_hint' => (float) $p->allocated_hint,
                'allocated_hint_note' => 'informational-only',
            ])->all(),
        ];
    }

    private function variantAttributes(mixed $variant): array
    {
        if (!$variant->relationLoaded('attributeProducts')) {
            return [];
        }

        $out = [];
        foreach ($variant->attributeProducts as $ap) {
            $av = $ap->attributeValue ?? null;
            $attr = $av?->attribute ?? null;
            if ($av) {
                $out[] = [
                    'attribute' => $attr?->name ?? $attr?->slug ?? null,
                    'value' => $av->value ?? $av->name ?? null,
                ];
            }
        }

        return $out;
    }
}
