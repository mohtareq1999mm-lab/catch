<?php

namespace Database\Seeders;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Database\Seeder;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

/**
 * Canonical warehouse-bin master + placement-coverage report (true data).
 *
 * SCOPE (deliberately narrow):
 * - Ensures the TRUE bin set exists in the default warehouse (union of the
 *   verified WarehouseSeeder + WmsOperationsSeeder sets): receiving,
 *   storage, picking, packing — all ACTIVE + placeable. Idempotent:
 *   firstOrCreate by (warehouse_id, code); existing rows are NEVER
 *   rewritten (bin identity, warehouse link and live names are truth).
 * - REPORTS per-SKU live-placement coverage (placed vs unplaced). It does
 *   NOT fabricate placement quantities: bin quantities are physical truth
 *   and may only be written by the canonical flows (stock-receipts increment
 *   authority+hints, stock-transfers move hints). A zero-quantity row would
 *   neither satisfy allocation (hasStock requires quantity > 0) nor reserve
 *   anything (zero rows do not occupy), so creating them would be noise.
 * - Unplaced SKUs with sellable stock must be received via
 *   POST /api/v1/admin/locations/{location}/stock-receipts (true flow).
 *
 * Standalone on purpose: NOT wired into DatabaseSeeder (which is not
 * re-runnable — see SEED-001). Run: php artisan db:seed
 * --class=Database\\Seeders\\WarehouseBinMasterSeeder
 */
class WarehouseBinMasterSeeder extends Seeder
{
    public function run(): void
    {
        // Fail-closed: deterministic bin/placement baseline must never run
        // against production data.
        abort_if(app()->isProduction(), 403, 'WarehouseBinMasterSeeder is blocked in production.');

        $warehouse = Warehouse::where('is_default', true)->orderBy('id')->first();
        if (!$warehouse) {
            throw new \RuntimeException('No default warehouse: cannot seed bins without a home warehouse.');
        }
        if ($warehouse->status !== 'active') {
            throw new \RuntimeException("Default warehouse #{$warehouse->id} ({$warehouse->code}) is not active.");
        }

        // Canonical true set = verified WMS operational bins first (they carry
        // scanner barcodes + priorities the picking tests rely on), then the
        // warehouse storage bins. Union == the 11 live bins in catch.
        $bins = [
            ['code' => 'REC-01', 'barcode' => 'LOC-REC-01', 'name' => 'منطقة الاستلام (Receiving)', 'type' => Location::TYPE_RECEIVING, 'priority' => 20],
            ['code' => 'PICK-01', 'barcode' => 'LOC-PICK-01', 'name' => 'منطقة اللم السريع (Fast Pick)', 'type' => Location::TYPE_PICKING, 'priority' => 15],
            ['code' => 'A-01-01', 'barcode' => 'LOC-A-01-01', 'name' => 'ممر A - رف 1 - خانة 1', 'type' => Location::TYPE_STORAGE, 'priority' => 10],
            ['code' => 'A-01-02', 'barcode' => 'LOC-A-01-02', 'name' => 'ممر A - رف 1 - خانة 2', 'type' => Location::TYPE_STORAGE, 'priority' => 9],
            ['code' => 'PACK-01', 'barcode' => 'LOC-PACK-01', 'name' => 'منطقة تجهيز التغليف (Packing)', 'type' => Location::TYPE_PACKING, 'priority' => 5],
            ['code' => 'A-01', 'barcode' => null, 'name' => 'Aisle A - Shelf 01', 'type' => Location::TYPE_STORAGE, 'priority' => 10],
            ['code' => 'A-02', 'barcode' => null, 'name' => 'Aisle A - Shelf 02', 'type' => Location::TYPE_STORAGE, 'priority' => 9],
            ['code' => 'A-03', 'barcode' => null, 'name' => 'Aisle A - Shelf 03', 'type' => Location::TYPE_STORAGE, 'priority' => 8],
            ['code' => 'B-01', 'barcode' => null, 'name' => 'Aisle B - Shelf 01', 'type' => Location::TYPE_STORAGE, 'priority' => 7],
            ['code' => 'B-02', 'barcode' => null, 'name' => 'Aisle B - Shelf 02', 'type' => Location::TYPE_STORAGE, 'priority' => 6],
            ['code' => 'BULK-01', 'barcode' => null, 'name' => 'Bulk Storage Zone 01', 'type' => Location::TYPE_STORAGE, 'priority' => 5],
        ];

        $created = 0;
        $drifted = [];
        foreach ($bins as $bin) {
            $row = Location::firstOrCreate(
                ['warehouse_id' => $warehouse->id, 'code' => $bin['code']],
                [
                    'barcode' => $bin['barcode'],
                    'name' => $bin['name'],
                    'type' => $bin['type'],
                    'status' => Location::STATUS_ACTIVE,
                    'priority' => $bin['priority'],
                ]
            );
            if ($row->wasRecentlyCreated) {
                $created++;
            } elseif ($row->barcode !== $bin['barcode'] || $row->type !== $bin['type']
                || (int) $row->priority !== (int) $bin['priority'] || $row->status !== Location::STATUS_ACTIVE
            ) {
                // Never rewrite live truth — report divergence (e.g. a
                // WarehouseSeeder-first database keeps its own PICK-01 name
                // and no barcode) so scanner expectations stay visible.
                $drifted[] = $bin['code'];
            }
        }

        // Coverage report (read-only): every simple-product + variant SKU vs
        // ALLOCATABLE placements. Mirrors the allocator exactly
        // (ProductLocationService::allocateFromLocations + scopePlaceable):
        // quantity must exceed allocated_hint (a fully-hinted row allocates
        // nothing), location must be active + NULL-or-placeable type in the
        // SAME warehouse. Per-SKU exists() is fine for operational catalogs;
        // chunk/aggregate if this ever runs against very large catalogs.
        $placeable = fn ($q) => $q->where('locations.warehouse_id', $warehouse->id)
            ->where('locations.status', Location::STATUS_ACTIVE)
            ->where(fn ($t) => $t->whereNull('locations.type')->orWhereIn('locations.type', Location::PLACEABLE_TYPES));
        $unplaced = [];
        $checked = 0;
        foreach (Product::orderBy('id')->get(['id', 'sku']) as $product) {
            $checked++;
            $live = ProductLocation::forWarehouse($warehouse->id)
                ->where('product_locations.product_id', $product->id)
                ->whereNull('product_locations.product_variant_id')
                ->whereColumn('product_locations.quantity', '>', 'product_locations.allocated_hint')
                ->whereHas('location', $placeable)
                ->exists();
            if (!$live) {
                $unplaced[] = "product:{$product->id}";
            }
        }
        foreach (ProductVariant::orderBy('id')->get(['id', 'product_id']) as $variant) {
            $checked++;
            $live = ProductLocation::forWarehouse($warehouse->id)
                ->where('product_locations.product_id', $variant->product_id)
                ->where('product_locations.product_variant_id', $variant->id)
                ->whereColumn('product_locations.quantity', '>', 'product_locations.allocated_hint')
                ->whereHas('location', $placeable)
                ->exists();
            if (!$live) {
                $unplaced[] = "variant:{$variant->id}";
            }
        }

        $this->command?->info("Warehouse {$warehouse->code}: bins ensured (" . count($bins) . ' declared, ' . $created . ' created).');
        if ($drifted !== []) {
            $this->command?->warn('Bin attribute drift vs canonical master (live truth kept, NOT rewritten): ' . implode(', ', $drifted));
        }
        $this->command?->info("Placement coverage: " . ($checked - count($unplaced)) . "/{$checked} SKUs allocatable.");
        if ($unplaced !== []) {
            $this->command?->warn('Unplaced SKUs (receive via POST stock-receipts, true flow — quantities never fabricated here): ' . implode(', ', $unplaced));
        }
    }
}
