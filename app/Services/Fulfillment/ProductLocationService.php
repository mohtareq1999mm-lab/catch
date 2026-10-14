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
     * Verify placement hints against central stock (SKU-level).
     * Phase 12: MONITOR, never a throwing gate (locked decision). Hints are
     * last-known placement; legitimate flows (returns, receiving) transiently
     * exceed central stock before it is updated. Returns drift status.
     */
    public function syncWithStock(int $productId, ?int $variantId = null): bool
    {
        return DB::transaction(function () use ($productId, $variantId) {
            if ($variantId !== null) {
                $authority = \Marvel\Database\Models\ProductVariant::where('id', $variantId)
                    ->lockForUpdate()
                    ->firstOrFail();
                $totalInLocations = ProductLocation::where('product_id', $productId)
                    ->where('product_variant_id', $variantId)
                    ->sum('quantity');
            } else {
                // Lock product row
                $authority = Product::where('id', $productId)
                    ->lockForUpdate()
                    ->firstOrFail();

                // Get total in locations for the SIMPLE SKU only (NULL variant).
                $totalInLocations = ProductLocation::where('product_id', $productId)
                    ->whereNull('product_variant_id')
                    ->sum('quantity');
            }

            $stockQuantity = $authority->stock_quantity;

            // Validate invariant
            if ($totalInLocations > $stockQuantity) {
                Log::warning('Product location quantity exceeds stock (drift monitor)', [
                    'product_id' => $productId,
                    'product_variant_id' => $variantId,
                    'stock_quantity' => $stockQuantity,
                    'total_in_locations' => $totalInLocations,
                ]);

                return false;
            }

            Log::info('Product location sync validated', [
                'product_id' => $productId,
                'product_variant_id' => $variantId,
                'stock_quantity' => $stockQuantity,
                'total_in_locations' => $totalInLocations,
            ]);

            return true;
        });
    }

    /**
     * Allocate product from best available locations (SKU-level).
     * Phase 7: allocation reads PLACEMENT HINTS from active, placeable
     * locations only (quarantine/damaged/returns/inactive excluded). It never
     * mutates central inventory and never decides sellability.
     */
    public function allocateFromLocations(
        int $productId,
        float $requiredQuantity,
        ?int $warehouseId = null,
        ?int $variantId = null
    ): array {
        $query = ProductLocation::where('product_locations.product_id', $productId)
            ->whereHas('location', fn ($q) => $q->placeable())
            ->hasStock();

        if ($variantId === null) {
            $query->whereNull('product_locations.product_variant_id');
        } else {
            $query->where('product_locations.product_variant_id', $variantId);
        }

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
                __('wms.placement.insufficient', ['required' => $requiredQuantity, 'found' => ($requiredQuantity - $remaining)])
            );
        }

        return $allocations;
    }

    /**
     * Suggest placement hints for a warehouse (§14 system suggestion).
     * Thin alias so pickers/retry paths read through one entry point.
     */
    public function suggestForWarehouse(int $productId, float $requiredQuantity, int $warehouseId, ?int $variantId = null): array
    {
        return $this->allocateFromLocations($productId, $requiredQuantity, $warehouseId, $variantId);
    }

    /**
     * Occupied-location policy (single shared enforcement point): one
     * SKU per location. Refuses when the location already hosts a
     * DIFFERENT SKU (different product OR different variant of the same
     * parent) with live quantity (quantity > 0 or allocated_hint > 0).
     * Zero-quantity rows are retained history and do not occupy.
     *
     * Backward compat: $variantId defaults to null (simple SKU).
     *
     * Must be called INSIDE the caller's write transaction BEFORE any
     * write: locks the location row first so concurrent claimants of
     * different SKUs serialize (no check-then-act race), and a refusal
     * persists nothing.
     *
     * @throws \InvalidArgumentException with wms.stock.location_occupied.
     */
    public function assertLocationVacantFor(int $locationId, int $productId, ?int $variantId = null): Location
    {
        $location = Location::query()->whereKey($locationId)->lockForUpdate()->firstOrFail();

        // E1 concurrency backstop (proven 2026-10-14): this MUST be a locking
        // read. Under REPEATABLE-READ a plain GET uses the txn snapshot, so a
        // second concurrent receiver blocked on the location lock above still
        // would not see the winner's committed placement and both would post
        // different SKUs into one bin. FOR UPDATE forces a current read after
        // the location lock serializes the claimants.
        $occupant = ProductLocation::query()
            ->where('location_id', $location->id)
            ->where(function ($q) {
                $q->where('quantity', '>', 0)->orWhere('allocated_hint', '>', 0);
            })
            ->lockForUpdate()
            ->get()
            ->first(fn (ProductLocation $p) => (int) $p->product_id !== $productId || ((int) ($p->product_variant_id ?? 0) !== (int) ($variantId ?? 0)));

        if ($occupant) {
            // Labels stay raw IDs: the lang string already prefixes `#`
            // (file convention `#:occupant`). Variant SKUs append `:Vn`
            // only when a variant is actually present.
            $occupantLabel = $occupant->product_variant_id ? "{$occupant->product_id}:V{$occupant->product_variant_id}" : "{$occupant->product_id}";
            $requestLabel = $variantId ? "{$productId}:V{$variantId}" : "{$productId}";
            throw new \InvalidArgumentException(
                __('wms.stock.location_occupied', ['location' => $locationId, 'occupant' => $occupantLabel, 'product' => $requestLabel])
            );
        }

        return $location;
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
                __('wms.placement.mismatch', ['id' => $productLocation->id, 'warehouse' => $productLocation->warehouse_id, 'location' => $locationWarehouse])
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
                throw new \Exception(__('wms.placement.negative'));
            }

            if ($newQuantity < $productLocation->allocated_hint) {
                throw new \Exception(
                    __('wms.placement.below_hint', ['hint' => $productLocation->allocated_hint])
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

            // Sync with stock (SKU-level: monitor the placement's own SKU,
            // simple rows only against the parent authority).
            $this->syncWithStock($productLocation->product_id, $productLocation->product_variant_id !== null ? (int) $productLocation->product_variant_id : null);
        });
    }
}
