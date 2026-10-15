<?php

namespace App\Console\Commands;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;

/**
 * Product-catalog reconciliation report (READ-ONLY, always dry-run).
 *
 * Enumerates the ENTIRE catalog — never a hardcoded subset — chunked with
 * bounded memory, and reports coverage, defects, and unplaced SKUs.
 * WRITES NOTHING, in every mode, on every database.
 *
 * Mapping-driven placement writes belong to exactly one canonical workflow:
 * `inventory:backfill-placements` (dry-run probe + apply through
 * StockOperationService::recordExistingPlacement). This command intentionally
 * has no apply mode so a read-only audit can never become a mutation.
 */
class ReconcileProductCatalog extends Command
{
    protected $signature = 'inventory:reconcile-catalog
        {--warehouse= : Scope placement coverage to one warehouse ID}
        {--chunk=500 : Rows per chunk for catalog iteration (50..5000)}
        {--json : Output machine-readable JSON instead of human-readable lines}';

    protected $description = 'Read-only reconciliation of the full product catalog vs placement hints (writes nothing)';

    public function handle(): int
    {
        $chunk = min(5000, max(50, (int) $this->option('chunk')));
        $warehouseId = $this->resolveWarehouseOption();
        if ($warehouseId === false) {
            return self::INVALID;
        }

        $report = $this->reconcile($warehouseId, $chunk);

        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return $report['status'] === 'clean' ? self::SUCCESS : self::FAILURE;
        }

        $this->renderHuman($report);

        return $report['status'] === 'clean' ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return int|false Warehouse ID, null for global, false for invalid input.
     */
    private function resolveWarehouseOption(): int|null|false
    {
        $raw = $this->option('warehouse');
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!ctype_digit((string) $raw) || (int) $raw <= 0) {
            $this->error('Invalid --warehouse: expected a positive warehouse ID.');

            return false;
        }
        if (!DB::table('warehouses')->where('id', (int) $raw)->exists()) {
            $this->error("Unknown --warehouse: no warehouse #{$raw}.");

            return false;
        }

        return (int) $raw;
    }

    /**
     * Read-only full-catalog reconciliation. Chunked; bounded memory.
     *
     * No transaction wrapper: every check is a plain SELECT (no locks, no
     * writes), so no snapshot is held across the scan and writers are never
     * blocked. Per-row existence checks are N+1 by query count but O(1) in
     * memory; revisit with aggregate preloading if a production scan proves
     * too slow (revisit trigger: wall time > 10 min on the target catalog).
     */
    private function reconcile(?int $warehouseId, int $chunk): array
    {
        $counts = [
                'products_total' => 0,
                'products_active' => 0,
                'products_inactive' => 0,
                'products_trashed' => 0,
                'products_simple' => 0,
                'products_variable' => 0,
                'variants_total' => 0,
                'placements_total' => 0,
                'placements_valid' => 0,
                'placements_invalid_refs' => 0,
                'placements_warehouse_drift' => 0,
                'placements_inactive_bin' => 0,
                'placements_duplicate_groups' => 0,
                'skus_unplaced_with_stock' => 0,
                'digital_with_live_placement' => 0,
                'duplicate_skus' => 0,
            ];
            $samples = [
                'unplaced' => [],
                'invalid_refs' => [],
                'warehouse_drift' => [],
                'inactive_bin' => [],
                'duplicates' => [],
                'duplicate_skus' => [],
                'digital' => [],
            ];

            $counts['products_trashed'] = Product::onlyTrashed()->count();
            Product::orderBy('id')->chunk($chunk, function ($products) use (&$counts) {
                foreach ($products as $product) {
                    $counts['products_total']++;
                    if ((int) $product->status === 1) {
                        $counts['products_active']++;
                    } else {
                        $counts['products_inactive']++;
                    }
                    if ($product->variations()->exists()) {
                        $counts['products_variable']++;
                    } else {
                        $counts['products_simple']++;
                    }
                }
            });

            $counts['variants_total'] = (int) ProductVariant::count();

            $hasItemType = Schema::hasColumn('products', 'item_type');
            $placeableTypes = Location::PLACEABLE_TYPES;

            ProductLocation::orderBy('id')->chunk($chunk, function ($rows) use (&$counts, &$samples, $warehouseId, $placeableTypes, $hasItemType) {
                foreach ($rows as $row) {
                    if ($warehouseId !== null && (int) $row->warehouse_id !== $warehouseId) {
                        continue;
                    }
                    $counts['placements_total']++;
                    $bad = false;

                    $productExists = Product::withTrashed()->whereKey($row->product_id)->exists();
                    $location = Location::whereKey($row->location_id)->first();
                    if (!$productExists || $location === null) {
                        $counts['placements_invalid_refs']++;
                        if (count($samples['invalid_refs']) < 5) {
                            $samples['invalid_refs'][] = ['id' => $row->id, 'product_id' => $row->product_id, 'location_id' => $row->location_id];
                        }
                        $bad = true;
                    }
                    if ($row->product_variant_id !== null) {
                        $variant = ProductVariant::whereKey($row->product_variant_id)->first();
                        if ($variant === null || (int) $variant->product_id !== (int) $row->product_id) {
                            $counts['placements_invalid_refs']++;
                            if (count($samples['invalid_refs']) < 10) {
                                $samples['invalid_refs'][] = ['id' => $row->id, 'type' => 'variant_mismatch', 'product_id' => $row->product_id, 'product_variant_id' => $row->product_variant_id];
                            }
                            $bad = true;
                        }
                    }
                    if ($location !== null && (int) $location->warehouse_id !== (int) $row->warehouse_id) {
                        $counts['placements_warehouse_drift']++;
                        if (count($samples['warehouse_drift']) < 5) {
                            $samples['warehouse_drift'][] = ['id' => $row->id, 'placement_warehouse' => $row->warehouse_id, 'location_warehouse' => $location->warehouse_id];
                        }
                        $bad = true;
                    }
                    $live = (float) $row->quantity > 0 || (float) $row->allocated_hint > 0;
                    if ($live && $location !== null
                        && ($location->status !== Location::STATUS_ACTIVE
                            || ($location->type !== null && !in_array($location->type, $placeableTypes, true)))) {
                        $counts['placements_inactive_bin']++;
                        if (count($samples['inactive_bin']) < 5) {
                            $samples['inactive_bin'][] = ['id' => $row->id, 'location_id' => $row->location_id, 'status' => $location->status, 'type' => $location->type];
                        }
                        $bad = true;
                    }
                    if ($hasItemType && $live && $productExists) {
                        $itemType = Product::withTrashed()->whereKey($row->product_id)->value('item_type');
                        if ($itemType === ItemType::DIGITAL) {
                            $counts['digital_with_live_placement']++;
                            if (count($samples['digital']) < 5) {
                                $samples['digital'][] = ['id' => $row->id, 'product_id' => $row->product_id];
                            }
                            $bad = true;
                        }
                    }
                    if (!$bad) {
                        $counts['placements_valid']++;
                    }
                }
            });

            // Duplicate SKU→location groups (unique backstop is the final
            // authority; this is the report side).
            DB::table('product_locations')
                ->selectRaw('product_id, COALESCE(product_variant_id, 0) as variant_key, location_id, COUNT(*) as dup_rows')
                ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
                ->groupByRaw('product_id, COALESCE(product_variant_id, 0), location_id')
                ->havingRaw('COUNT(*) > 1')
                ->orderByRaw('product_id, COALESCE(product_variant_id, 0), location_id')
                ->chunk(200, function ($groups) use (&$counts, &$samples) {
                    foreach ($groups as $group) {
                        $counts['placements_duplicate_groups']++;
                        if (count($samples['duplicates']) < 5) {
                            $samples['duplicates'][] = (array) $group;
                        }
                    }
                });

            // Duplicate product SKUs (never assume uniqueness).
            DB::table('products')
                ->selectRaw('sku, COUNT(*) as sku_rows')
                ->whereNotNull('sku')
                ->groupBy('sku')
                ->havingRaw('COUNT(*) > 1')
                ->orderBy('sku')
                ->chunk(200, function ($groups) use (&$counts, &$samples) {
                    foreach ($groups as $group) {
                        if (count($samples['duplicate_skus']) < 5) {
                            $samples['duplicate_skus'][] = (array) $group;
                        }
                    }
                    $counts['duplicate_skus'] += count($groups);
                });

            // Unplaced SKUs with positive authority stock (chunked, bounded).
            $unplacedCount = 0;
            Product::where('stock_quantity', '>', 0)
                ->when($hasItemType, fn ($q) => $q->where(fn ($w) => $w->whereNull('item_type')->orWhere('item_type', '!=', ItemType::DIGITAL)))
                ->orderBy('id')
                ->chunk($chunk, function ($products) use (&$unplacedCount, &$samples, $warehouseId) {
                    foreach ($products as $product) {
                        if ($product->variations()->exists()) {
                            continue; // authority lives on variants for variable parents
                        }
                        $has = ProductLocation::where('product_id', $product->id)
                            ->whereNull('product_variant_id')
                            ->whereColumn('quantity', '>', 'allocated_hint')
                            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
                            ->whereHas('location', fn ($q) => $q->where('status', Location::STATUS_ACTIVE)
                                ->where(fn ($t) => $t->whereNull('type')->orWhereIn('type', Location::PLACEABLE_TYPES)))
                            ->exists();
                        if (!$has) {
                            $unplacedCount++;
                            if (count($samples['unplaced']) < 10) {
                                $samples['unplaced'][] = ['type' => 'simple', 'product_id' => $product->id, 'sku' => $product->sku, 'stock_quantity' => $product->stock_quantity];
                            }
                        }
                    }
                });
            ProductVariant::where('product_variants.stock_quantity', '>', 0)
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->where(fn ($q) => $q->whereNull('products.item_type')->orWhere('products.item_type', '!=', ItemType::DIGITAL))
                ->orderBy('product_variants.id')
                ->chunk($chunk, function ($variants) use (&$unplacedCount, &$samples, $warehouseId) {
                    foreach ($variants as $variant) {
                        $has = ProductLocation::where('product_variant_id', $variant->id)
                            ->whereColumn('quantity', '>', 'allocated_hint')
                            ->when($warehouseId !== null, fn ($q) => $q->where('warehouse_id', $warehouseId))
                            ->whereHas('location', fn ($q) => $q->where('status', Location::STATUS_ACTIVE)
                                ->where(fn ($t) => $t->whereNull('type')->orWhereIn('type', Location::PLACEABLE_TYPES)))
                            ->exists();
                        if (!$has) {
                            $unplacedCount++;
                            if (count($samples['unplaced']) < 20) {
                                $samples['unplaced'][] = ['type' => 'variant', 'variant_id' => $variant->id, 'product_id' => $variant->product_id, 'sku' => $variant->sku, 'stock_quantity' => $variant->stock_quantity];
                            }
                        }
                    }
                });
            $counts['skus_unplaced_with_stock'] = $unplacedCount;

            $findings = $counts['placements_invalid_refs'] + $counts['placements_warehouse_drift']
                + $counts['placements_inactive_bin'] + $counts['placements_duplicate_groups']
                + $counts['digital_with_live_placement'] + $counts['duplicate_skus'];

            return [
                'scope' => $warehouseId === null ? 'global' : "warehouse:{$warehouseId}",
                'status' => $findings > 0 || $unplacedCount > 0 ? 'findings' : 'clean',
                'counts' => $counts,
                'samples' => $samples,
                'note' => 'Unplaced SKUs are coverage info (receive via stock-receipts), not auto-fixable: the command never invents locations.',
            ];
    }

    private function renderHuman(array $report): void
    {
        $this->line('Scope: ' . $report['scope']);
        $this->line('Status: ' . $report['status']);
        foreach ($report['counts'] as $key => $value) {
            $this->line(sprintf('  %-32s : %d', $key, $value));
        }
        foreach ($report['samples'] as $key => $rows) {
            if ($rows === []) {
                continue;
            }
            $this->line("  samples.{$key}:");
            foreach (array_slice($rows, 0, 5) as $row) {
                $this->line('    - ' . json_encode($row, JSON_UNESCAPED_UNICODE));
            }
        }
        $this->line('Note: ' . $report['note']);
        $this->line('Mapping writes: use inventory:backfill-placements --mapping=<file> [--apply] (canonical workflow).');
    }
}
