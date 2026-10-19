<?php

namespace App\Services\Warehouse;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\Package;
use App\Models\Fulfillment\ProductLocation;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

class BarcodeScanService
{
    /**
     * Common location prefixes used in warehouses.
     */
    private const LOCATION_PREFIXES = [
        'LOC-', 'BIN-', 'ZONE-', 'AISLE-', 'SHELF-', 'RACK-', 'PICK-', 'BULK-',
    ];

    /**
     * Common package / parcel prefixes.
     */
    private const PACKAGE_PREFIXES = [
        'PKG-', 'PACKAGE-', 'SHP-', 'TRACK-',
    ];

    /**
     * Smart heuristic type detection from raw barcode string without database round-trips.
     *
     * @return 'product'|'location'|'package'|null
     */
    public function detectProbableType(string $code): ?string
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $upper = strtoupper($code);

        // 1. Location prefix detection (e.g. LOC-01, BIN-A-12)
        foreach (self::LOCATION_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'location';
            }
        }

        // 2. Standard rack/aisle/shelf format (e.g. A-01, B-02-01, AA-12-3)
        if (preg_match('/^[A-Z]{1,3}-\d{1,4}(-\d{1,4})*$/i', $code) === 1) {
            return 'location';
        }

        // 3. Package prefix detection (e.g. PKG-12345)
        foreach (self::PACKAGE_PREFIXES as $prefix) {
            if (str_starts_with($upper, $prefix)) {
                return 'package';
            }
        }

        // 4. Retail standard product barcodes: EAN-8 (8), UPC-A (12), EAN-13 (13), GTIN-14 (14)
        if (ctype_digit($code) && in_array(strlen($code), [8, 12, 13, 14], true)) {
            return 'product';
        }

        return null;
    }

    /**
     * Resolve barcode with context-aware type targeting and smart heuristic routing.
     *
     * @return array{kind: string, detected_by: string, data: array}|null
     */
    public function scan(string $code, ?string $type = null, ?int $warehouseId = null): ?array
    {
        $code = trim($code);
        if ($code === '') {
            return null;
        }

        $normalizedType = $type !== null ? strtolower(trim($type)) : null;
        if ($normalizedType === 'auto' || $normalizedType === '') {
            $normalizedType = null;
        }

        // 1. Targeted explicit scan: zero wasted queries on other tables
        if ($normalizedType !== null) {
            return match ($normalizedType) {
                'location' => ($data = $this->scanLocation($code, $warehouseId))
                    ? ['kind' => 'location', 'detected_by' => 'explicit', 'data' => $data]
                    : null,
                'product', 'variant' => ($data = $this->scanProduct($code, $warehouseId))
                    ? ['kind' => 'product', 'detected_by' => 'explicit', 'data' => $data]
                    : null,
                'package' => ($data = $this->scanPackage($code))
                    ? ['kind' => 'package', 'detected_by' => 'explicit', 'data' => $data]
                    : null,
                default => null,
            };
        }

        // 2. Smart heuristic detection: avoids multi-table sequential scan
        $probable = $this->detectProbableType($code);

        if ($probable === 'product') {
            $product = $this->scanProduct($code, $warehouseId);
            if ($product !== null) {
                return ['kind' => 'product', 'detected_by' => 'pattern', 'data' => $product];
            }
            // Fallback in case of numeric collision with bin/shelf code
            $location = $this->scanLocation($code, $warehouseId);
            if ($location !== null) {
                return ['kind' => 'location', 'detected_by' => 'fallback', 'data' => $location];
            }
            $package = $this->scanPackage($code);
            if ($package !== null) {
                return ['kind' => 'package', 'detected_by' => 'fallback', 'data' => $package];
            }

            return null;
        }

        if ($probable === 'location') {
            $location = $this->scanLocation($code, $warehouseId);
            if ($location !== null) {
                return ['kind' => 'location', 'detected_by' => 'pattern', 'data' => $location];
            }
            // Fallback in case of collision with product SKU
            $product = $this->scanProduct($code, $warehouseId);
            if ($product !== null) {
                return ['kind' => 'product', 'detected_by' => 'fallback', 'data' => $product];
            }
            $package = $this->scanPackage($code);
            if ($package !== null) {
                return ['kind' => 'package', 'detected_by' => 'fallback', 'data' => $package];
            }

            return null;
        }

        if ($probable === 'package') {
            $package = $this->scanPackage($code);
            if ($package !== null) {
                return ['kind' => 'package', 'detected_by' => 'pattern', 'data' => $package];
            }
            $location = $this->scanLocation($code, $warehouseId);
            if ($location !== null) {
                return ['kind' => 'location', 'detected_by' => 'fallback', 'data' => $location];
            }
            $product = $this->scanProduct($code, $warehouseId);
            if ($product !== null) {
                return ['kind' => 'product', 'detected_by' => 'fallback', 'data' => $product];
            }

            return null;
        }

        // 3. Ambiguous alphanumeric code: inspect indexed location table first, then product, then package
        $location = $this->scanLocation($code, $warehouseId);
        if ($location !== null) {
            return ['kind' => 'location', 'detected_by' => 'lookup', 'data' => $location];
        }

        $product = $this->scanProduct($code, $warehouseId);
        if ($product !== null) {
            return ['kind' => 'product', 'detected_by' => 'lookup', 'data' => $product];
        }

        $package = $this->scanPackage($code);
        if ($package !== null) {
            return ['kind' => 'package', 'detected_by' => 'lookup', 'data' => $package];
        }

        return null;
    }

    /**
     * Targeted location scan with placements.
     */
    public function scanLocation(string $code, ?int $warehouseId = null): ?array
    {
        $baseQuery = Location::with([
            'warehouse:id,code,name',
            'productLocations' => function ($q) {
                $q->with([
                    'product:id,name,sku,price',
                    'variant:id,sku,product_id',
                ])->where('quantity', '>', 0);
            },
        ]);

        if ($warehouseId !== null) {
            $baseQuery->where('warehouse_id', $warehouseId);
        }

        // 1. By unique barcode
        $location = (clone $baseQuery)->where('barcode', $code)->first();

        // 2. By unique code within warehouse
        if (!$location) {
            $location = (clone $baseQuery)->where('code', $code)->first();
        }

        if (!$location) {
            return null;
        }

        $storedItems = [];
        foreach ($location->productLocations as $pl) {
            $sku = $pl->variant?->sku ?? $pl->product?->sku ?? 'UNKNOWN';
            $name = $pl->product?->name ?? 'Unknown Product';
            $storedItems[] = [
                'placement_id' => $pl->id,
                'product_id' => $pl->product_id,
                'product_variant_id' => $pl->product_variant_id,
                'sku' => $sku,
                'name' => $name,
                'quantity' => (float) $pl->quantity,
                'allocated_hint' => (float) $pl->allocated_hint,
                'available_quantity' => (float) $pl->availableQuantity(),
            ];
        }

        return [
            'id' => $location->id,
            'code' => $location->code,
            'name' => $location->name,
            'barcode' => $location->barcode,
            'type' => $location->type,
            'status' => $location->status,
            'priority' => (int) $location->priority,
            'warehouse_id' => $location->warehouse_id,
            'warehouse' => $location->warehouse ? [
                'id' => $location->warehouse->id,
                'code' => $location->warehouse->code,
                'name' => $location->warehouse->name,
            ] : null,
            'stored_items_count' => count($storedItems),
            'stored_items' => $storedItems,
        ];
    }

    /**
     * Targeted product/variant scan with current warehouse placements.
     */
    public function scanProduct(string $code, ?int $warehouseId = null): ?array
    {
        // 1. Check ProductVariant by SKU
        $variant = ProductVariant::where('sku', $code)->with('product')->first();
        if ($variant !== null) {
            return $this->formatProductPayload($variant->product, $variant, $warehouseId);
        }

        // 2. Check Product by SKU
        $product = Product::where('sku', $code)->first();
        if ($product !== null) {
            return $this->formatProductPayload($product, null, $warehouseId);
        }

        // 3. Check numeric primary key ID if code is purely numeric
        if (ctype_digit($code)) {
            $id = (int) $code;
            $productById = Product::find($id);
            if ($productById !== null) {
                return $this->formatProductPayload($productById, null, $warehouseId);
            }

            $variantById = ProductVariant::with('product')->find($id);
            if ($variantById !== null) {
                return $this->formatProductPayload($variantById->product, $variantById, $warehouseId);
            }
        }

        return null;
    }

    /**
     * Targeted package scan.
     */
    public function scanPackage(string $code): ?array
    {
        $package = Package::where('barcode', $code)
            ->orWhere('package_number', $code)
            ->with(['fulfillment:id,fulfillment_number,status,warehouse_id'])
            ->first();

        if ($package === null) {
            return null;
        }

        return [
            'id' => $package->id,
            'package_number' => $package->package_number,
            'barcode' => $package->barcode,
            'status' => $package->status,
            'fulfillment_id' => $package->fulfillment_id,
            'fulfillment' => $package->fulfillment ? [
                'id' => $package->fulfillment->id,
                'fulfillment_number' => $package->fulfillment->fulfillment_number,
                'status' => $package->fulfillment->status,
                'warehouse_id' => $package->fulfillment->warehouse_id,
            ] : null,
        ];
    }

    private function formatProductPayload(?Product $product, ?ProductVariant $variant, ?int $warehouseId): array
    {
        $productId = $variant ? $variant->product_id : $product?->id;
        $variantId = $variant?->id;
        $sku = (string) ($variant ? $variant->sku : $product?->sku);
        $name = (string) ($product?->name ?? 'Unknown');
        $price = (float) ($variant?->price ?? $product?->price ?? 0);

        $stock = (int) ($variant?->stock_quantity ?? $product?->stock_quantity ?? 0);
        $reserved = (int) ($variant?->reserved_quantity ?? $product?->reserved_quantity ?? 0);
        $available = max(0, $stock - $reserved);

        // Fetch placements in current warehouse
        $placements = [];
        if ($productId) {
            $query = ProductLocation::forSku((int) $productId, $variantId)
                ->with('location:id,code,name,type,status,priority')
                ->where('quantity', '>', 0);

            if ($warehouseId !== null) {
                $query->where('warehouse_id', $warehouseId);
            }

            $locations = $query->get();
            foreach ($locations as $pl) {
                if (!$pl->location) {
                    continue;
                }
                $placements[] = [
                    'placement_id' => $pl->id,
                    'location_id' => $pl->location_id,
                    'location_code' => $pl->location->code,
                    'location_name' => $pl->location->name,
                    'location_type' => $pl->location->type,
                    'quantity' => (float) $pl->quantity,
                    'allocated_hint' => (float) $pl->allocated_hint,
                    'available_quantity' => (float) $pl->availableQuantity(),
                ];
            }
        }

        return [
            'product_id' => $productId,
            'product_variant_id' => $variantId,
            'is_variant' => $variant !== null,
            'sku' => $sku,
            'name' => $name,
            'price' => $price,
            'product_type' => $product?->product_type ?? 'simple',
            'stock_quantity' => $stock,
            'reserved_quantity' => $reserved,
            'available_quantity' => $available,
            'warehouse_id' => $warehouseId,
            'placements_count' => count($placements),
            'placements' => $placements,
        ];
    }
}
