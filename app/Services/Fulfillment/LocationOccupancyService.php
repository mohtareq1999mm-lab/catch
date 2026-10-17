<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Services\Warehouse\WarehouseAccess;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Collection;

class LocationOccupancyService
{
    public function __construct(
        private WarehouseAccess $access
    ) {}

    /**
     * Check occupancy for a given list of location IDs.
     * Categorizes bins into empty, occupied (with products and variants details), and not_found.
     *
     * @param array<int> $locationIds
     * @param int|null $warehouseId
     * @param Authenticatable|null $user
     * @return array{
     *     empty: array<int, array<string, mixed>>,
     *     occupied: array<int, array<string, mixed>>,
     *     not_found: array<int>
     * }
     */
    public function checkOccupancy(array $locationIds, ?int $warehouseId = null, ?Authenticatable $user = null): array
    {
        if (empty($locationIds)) {
            return [
                'empty' => [],
                'occupied' => [],
                'not_found' => [],
            ];
        }

        $query = Location::query()->whereIn('id', $locationIds);

        // Apply warehouse authorization scoping if user is not a global warehouse manager
        if ($user !== null) {
            $isGlobalManager = false;
            try {
                $isGlobalManager = method_exists($user, 'hasPermissionTo') && $user->hasPermissionTo('manage-warehouse', 'api');
            } catch (\Throwable) {
                $isGlobalManager = false;
            }

            if (!$isGlobalManager) {
                $home = $this->access->warehouseIdFor($user);
                if ($home !== null) {
                    $query->where('warehouse_id', $home);
                }
            }
        }

        if ($warehouseId !== null) {
            $query->where('warehouse_id', $warehouseId);
        }

        /** @var Collection<int, Location> $locations */
        $locations = $query
            ->with([
                'productLocations' => function ($q) {
                    $q->where(function ($sub) {
                        $sub->where('quantity', '>', 0)
                            ->orWhere('allocated_hint', '>', 0);
                    })->with([
                        'product:id,name,slug,sku,product_type,status',
                        'variant.attributeProducts.attributeValue.attribute',
                    ]);
                },
            ])
            ->get()
            ->keyBy('id');

        $empty = [];
        $occupied = [];
        $foundIds = $locations->keys()->all();
        $notFoundIds = array_values(array_diff($locationIds, $foundIds));

        foreach ($locations as $location) {
            /** @var Collection<int, ProductLocation> $activePlacements */
            $activePlacements = $location->productLocations;

            if ($activePlacements->isEmpty()) {
                $empty[] = $this->formatEmptyLocation($location);
                continue;
            }

            $totalQuantity = (float) $activePlacements->sum('quantity');

            // If placements sum to zero or less, treat as empty
            if ($totalQuantity <= 0) {
                $empty[] = $this->formatEmptyLocation($location);
                continue;
            }

            $occupied[] = $this->formatOccupiedLocation($location, $activePlacements, $totalQuantity);
        }

        return [
            'empty' => $empty,
            'occupied' => $occupied,
            'not_found' => $notFoundIds,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatEmptyLocation(Location $location): array
    {
        return [
            'id' => $location->id,
            'warehouse_id' => $location->warehouse_id,
            'code' => $location->code,
            'barcode' => $location->barcode,
            'name' => $location->name,
            'type' => $location->type,
            'status' => $location->status,
        ];
    }

    /**
     * @param Collection<int, ProductLocation> $placements
     * @return array<string, mixed>
     */
    private function formatOccupiedLocation(Location $location, Collection $placements, float $totalQuantity): array
    {
        $products = $placements->map(function (ProductLocation $p) {
            $product = $p->product;
            $variant = $p->variant;

            $variantData = null;
            if ($variant !== null) {
                $attributes = [];
                $attrProducts = $variant->relationLoaded('attributeProducts')
                    ? $variant->attributeProducts
                    : $variant->attributeProducts()->with('attributeValue.attribute')->get();

                if ($attrProducts) {
                    foreach ($attrProducts as $ap) {
                        $av = $ap->attributeValue ?? null;
                        $attr = $av?->attribute ?? null;
                        if ($av) {
                            $attrName = null;
                            if ($attr) {
                                if (method_exists($attr, 'getTranslations')) {
                                    $trans = $attr->getTranslations('name');
                                    $attrName = $trans[app()->getLocale()] ?? ($trans['en'] ?? (is_array($trans) && count($trans) ? reset($trans) : null));
                                }
                                if (!$attrName) {
                                    $attrName = $attr->name ?: $attr->slug;
                                }
                                if (is_array($attrName)) {
                                    $attrName = $attrName[app()->getLocale()] ?? ($attrName['en'] ?? reset($attrName));
                                }
                            }

                            $val = null;
                            if (method_exists($av, 'getTranslations')) {
                                $trans = $av->getTranslations('value');
                                $val = $trans[app()->getLocale()] ?? ($trans['en'] ?? (is_array($trans) && count($trans) ? reset($trans) : null));
                            }
                            if (!$val) {
                                $val = $av->value ?: $av->name;
                            }
                            if (is_array($val)) {
                                $val = $val[app()->getLocale()] ?? ($val['en'] ?? reset($val));
                            }

                            $attributes[] = [
                                'name' => (string) ($attrName ?: ''),
                                'value' => (string) ($val ?: ''),
                                'attribute_id' => $attr?->id,
                                'attribute_value_id' => $av->id,
                            ];
                        }
                    }
                }

                $variantData = [
                    'id' => $variant->id,
                    'sku' => $variant->sku,
                    'price' => $variant->price !== null ? (float) $variant->price : null,
                    'sale_price' => $variant->sale_price !== null ? (float) $variant->sale_price : null,
                    'stock_quantity' => $variant->stock_quantity !== null ? (int) $variant->stock_quantity : (int) $variant->quantity,
                    'attributes' => $attributes,
                ];
            }

            return [
                'placement_id' => $p->id,
                'product_id' => $p->product_id,
                'product_name' => $product?->name,
                'product_slug' => $product?->slug,
                'product_sku' => $product?->sku,
                'quantity' => (float) $p->quantity,
                'allocated_hint' => (float) ($p->allocated_hint ?? 0),
                'available_quantity' => (float) $p->availableQuantity(),
                'has_variant' => $p->product_variant_id !== null,
                'variant' => $variantData,
            ];
        })->values()->all();

        return [
            'id' => $location->id,
            'warehouse_id' => $location->warehouse_id,
            'code' => $location->code,
            'barcode' => $location->barcode,
            'name' => $location->name,
            'type' => $location->type,
            'status' => $location->status,
            'total_quantity' => $totalQuantity,
            'products_count' => count($products),
            'products' => $products,
        ];
    }
}
