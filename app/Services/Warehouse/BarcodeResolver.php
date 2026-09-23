<?php

namespace App\Services\Warehouse;

use App\Exceptions\UnknownBarcodeException;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

/**
 * Phase 7: single barcode/identity resolution layer.
 *
 * Answers WHAT (product/variant SKU), WHERE (location barcode/code),
 * WHICH ORDER (order_number), WHICH FULFILLMENT (fulfillment_number).
 * Package/shipment kinds are added in P10/P11 when those tables exist.
 * Never encodes mutable location into product identity.
 */
class BarcodeResolver
{
    /**
     * @return array{kind: string, id: int|string, label: string}
     *
     * @throws UnknownBarcodeException
     */
    public function resolve(string $code, ?int $warehouseId = null): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new UnknownBarcodeException('empty');
        }

        // WHERE — location barcode first, then warehouse-scoped code.
        $locationQuery = Location::where('barcode', $code);
        if ($warehouseId !== null) {
            $locationQuery->where('warehouse_id', $warehouseId);
        }
        $location = $locationQuery->first();
        if (!$location && $warehouseId !== null) {
            $location = Location::where('warehouse_id', $warehouseId)->where('code', $code)->first();
        }
        if (!$location) {
            $location = Location::where('code', $code)->first();
        }
        if ($location) {
            return ['kind' => 'location', 'id' => $location->id, 'label' => $location->code];
        }

        // WHAT — product / variant SKU (exact match).
        $product = Product::where('sku', $code)->first();
        if ($product) {
            return ['kind' => 'product', 'id' => $product->id, 'label' => (string) $product->sku];
        }
        $variant = ProductVariant::where('sku', $code)->first();
        if ($variant) {
            return [
                'kind' => 'variant', 'id' => $variant->id,
                'label' => (string) $variant->sku,
                'product_id' => $variant->product_id ?? null,
            ];
        }

        // WHICH FULFILLMENT / WHICH ORDER.
        $fulfillment = Fulfillment::where('fulfillment_number', $code)->first();
        if ($fulfillment) {
            return ['kind' => 'fulfillment', 'id' => $fulfillment->id, 'label' => $fulfillment->fulfillment_number];
        }
        $order = Order::where('order_number', $code)->first();
        if ($order) {
            return ['kind' => 'order', 'id' => $order->id, 'label' => (string) $order->order_number];
        }

        throw new UnknownBarcodeException($code);
    }
}
