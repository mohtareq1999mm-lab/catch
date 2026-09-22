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
     * Sync product locations with stock quantity
     * CRITICAL: Ensures SUM(locations) <= Stock.stock_quantity
     */
    public function syncWithStock(int $productId): void
    {
        DB::transaction(function () use ($productId) {
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
                Log::error('Product location quantity exceeds stock', [
                    'product_id' => $productId,
                    'stock_quantity' => $stockQuantity,
                    'total_in_locations' => $totalInLocations,
                ]);

                throw new \Exception(
                    "Product location total ($totalInLocations) exceeds stock quantity ($stockQuantity)"
                );
            }

            Log::info('Product location sync validated', [
                'product_id' => $productId,
                'stock_quantity' => $stockQuantity,
                'total_in_locations' => $totalInLocations,
            ]);
        });
    }

    /**
     * Allocate product from best available locations
     */
    public function allocateFromLocations(
        int $productId,
        float $requiredQuantity,
        ?int $warehouseId = null
    ): array {
        $query = ProductLocation::where('product_id', $productId)
            ->hasStock()
            ->with('location');

        if ($warehouseId) {
            $query->forWarehouse($warehouseId);
        }

        // Order by location priority, then quantity
        $locations = $query
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

            if ($newQuantity < $productLocation->reserved_quantity) {
                throw new \Exception(
                    "Cannot reduce quantity below reserved amount ({$productLocation->reserved_quantity})"
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
