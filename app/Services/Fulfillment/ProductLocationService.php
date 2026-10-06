<?php

namespace App\Services\Fulfillment;

use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Location;
use Marvel\Database\Models\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProductLocationService
{
    /**
     * Verify placement hints against central stock.
     * Phase 12: MONITOR, never a throwing gate (locked decision). Hints are
     * last-known placement; legitimate flows (returns, receiving) transiently
     * exceed central stock before it is updated. Returns drift status.
     */
    public function syncWithStock(int $productId): bool
    {
        return DB::transaction(function () use ($productId) {
            // Lock product row
            $product = Product::where('id', $productId)
                ->lockForUpdate()
                ->firstOrFail();

            // Get total in locations
            $totalInLocations = ProductLocation::where('product_id', $productId)
                ->sum('quantity');

            $stockQuantity = $product->stock_quantity;

            // Validate invariant
            if ($totalInLocations > $stockQuantity) {
                Log::warning('Product location quantity exceeds stock (drift monitor)', [
                    'product_id' => $productId,
                    'stock_quantity' => $stockQuantity,
                    'total_in_locations' => $totalInLocations,
                ]);

                return false;
            }

            Log::info('Product location sync validated', [
                'product_id' => $productId,
                'stock_quantity' => $stockQuantity,
                'total_in_locations' => $totalInLocations,
            ]);

            return true;
        });
    }

    /**
     * Allocate product from best available locations.
     * Phase 7: allocation reads PLACEMENT HINTS from active, placeable
     * locations only (quarantine/damaged/returns/inactive excluded). It never
     * mutates central inventory and never decides sellability.
     */
    public function allocateFromLocations(
        int $productId,
        float $requiredQuantity,
        ?int $warehouseId = null
    ): array {
        $query = ProductLocation::where('product_locations.product_id', $productId)
            ->whereHas('location', fn ($q) => $q->placeable())
            ->hasStock();

        if ($warehouseId) {
            $query->where('product_locations.warehouse_id', $warehouseId);
        }

        // Order by location priority, then quantity. Eager-load locations:
        // the loop reads location code/warehouse per row (no N+1).
        $locations = $query
            ->with('location')
            ->join('locations', 'product_locations.location_id', '=', 'locations.id')
            ->orderBy('locations.priority', 'desc')
            ->orderBy('product_locations.quantity', 'desc')
            ->select('product_locations.*')
            ->get();

        $allocations = [];
        $remaining = $requiredQuantity;

        foreach ($locations as $productLocation) {
            if ($remaining <= 0) {
                break;
            }

            // Denorm guard (§34): placement warehouse must match its location.
            $this->assertConsistent($productLocation);

            $available = $productLocation->availableQuantity();
            $toAllocate = min($available, $remaining);

            if ($toAllocate > 0) {
                $allocations[] = [
                    'product_location_id' => $productLocation->id,
                    'location_id' => $productLocation->location_id,
                    'location_code' => $productLocation->location->code,
                    'warehouse_id' => $productLocation->warehouse_id,
                    'available' => $available,
                    'allocated' => $toAllocate,
                ];

                $remaining -= $toAllocate;
            }
        }

        if ($remaining > 0) {
            throw new \Exception(
                "Insufficient stock in locations. Required: $requiredQuantity, Found: " .
                ($requiredQuantity - $remaining)
            );
        }

        return $allocations;
    }

    /**
     * Suggest placement hints for a warehouse (§14 system suggestion).
     * Thin alias so pickers/retry paths read through one entry point.
     */
    public function suggestForWarehouse(int $productId, float $requiredQuantity, int $warehouseId): array
    {
        return $this->allocateFromLocations($productId, $requiredQuantity, $warehouseId);
    }

    /**
     * @throws \RuntimeException when product_locations.warehouse_id disagrees
     *   with locations.warehouse_id (denormalized for perf — never trusted).
     */
    public function assertConsistent(ProductLocation $productLocation): void
    {
        $locationWarehouse = $productLocation->location?->warehouse_id
            ?? Location::whereKey($productLocation->location_id)->value('warehouse_id');

        if ($locationWarehouse !== null && (int) $locationWarehouse !== (int) $productLocation->warehouse_id) {
            throw new \RuntimeException(
                "PLACEMENT_MISMATCH: product placement #{$productLocation->id} warehouse {$productLocation->warehouse_id} != location warehouse {$locationWarehouse}"
            );
        }
    }

    /**
     * Update location quantity (with validation)
     */
    public function updateLocationQuantity(
        int $productLocationId,
        float $delta,
        string $reason = 'manual_adjustment'
    ): void {
        DB::transaction(function () use ($productLocationId, $delta, $reason) {
            $productLocation = ProductLocation::lockForUpdate()->findOrFail($productLocationId);

            $newQuantity = $productLocation->quantity + $delta;

            if ($newQuantity < 0) {
                throw new \Exception("Cannot reduce quantity below zero");
            }

            if ($newQuantity < $productLocation->allocated_hint) {
                throw new \Exception(
                    "Cannot reduce quantity below allocated hint ({$productLocation->allocated_hint})"
                );
            }

            $productLocation->update(['quantity' => $newQuantity]);

            Log::info('Product location quantity updated', [
                'product_location_id' => $productLocationId,
                'old_quantity' => $productLocation->quantity - $delta,
                'delta' => $delta,
                'new_quantity' => $newQuantity,
                'reason' => $reason,
            ]);

            // Sync with stock
            $this->syncWithStock($productLocation->product_id);
        });
    }
}
