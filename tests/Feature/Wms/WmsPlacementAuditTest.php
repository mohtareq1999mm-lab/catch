<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Enums\ItemType;
use Tests\TestCase;

/**
 * Report-only placement reconciliation audit.
 *
 * The command WRITES NOTHING: it classifies discrepancies for a business
 * decision. Covers: aggregate drift (both directions), unplaced authority
 * stock (the false-CLEAN gap), duplicates, warehouse drift, invalid refs,
 * inactive bins, non-physical items, variant mismatch, scoping, JSON/human
 * equivalence, exit codes, read-only proof, and scale behavior.
 */
class WmsPlacementAuditTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    protected function setUp(): void
    {
        parent::setUp();

        $this->warehouse = Warehouse::create([
            'code' => 'WH-AUD', 'name' => 'Audit', 'status' => 'active', 'is_default' => true,
        ]);
    }

    private function location(string $code, string $status = Location::STATUS_ACTIVE): Location
    {
        return Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => $code, 'name' => 'Bin '.$code,
            'type' => Location::TYPE_STORAGE, 'status' => $status,
        ]);
    }

    private function simpleProduct(int $stock = 0): Product
    {
        return Product::create([
            'name' => 'Simple '.Str::random(6), 'slug' => 'simple-'.Str::random(8),
            'price' => 100, 'product_type' => 'simple', 'status' => 1,
            'in_stock' => true, 'quantity' => 0, 'stock_quantity' => $stock,
            'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
    }

    private function place(int $productId, int $locationId, float $qty, ?int $variantId = null): ProductLocation
    {
        return ProductLocation::create([
            'product_id' => $productId, 'product_variant_id' => $variantId,
            'location_id' => $locationId, 'warehouse_id' => $this->warehouse->id,
            'quantity' => $qty, 'allocated_hint' => 0,
        ]);
    }

    private function variableProductWithVariants(int $stockA = 0, int $stockB = 0): array
    {
        $p = Product::create([
            'name' => 'Var '.Str::random(6), 'slug' => 'var-'.Str::random(8),
            'price' => 200, 'product_type' => 'variable', 'status' => 1,
            'in_stock' => true, 'quantity' => 0, 'stock_quantity' => 0,
            'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
        $a = ProductVariant::create(['product_id' => $p->id, 'sku' => 'VA-'.Str::random(6), 'price' => 200, 'stock_quantity' => $stockA, 'reserved_quantity' => 0, 'sold_quantity' => 0, 'in_stock' => true]);
        $b = ProductVariant::create(['product_id' => $p->id, 'sku' => 'VB-'.Str::random(6), 'price' => 200, 'stock_quantity' => $stockB, 'reserved_quantity' => 0, 'sold_quantity' => 0, 'in_stock' => true]);

        return [$p, $a, $b];
    }

    /** @return array<string,mixed> */
    private function jsonAudit(array $params = []): array
    {
        // Explicit buffer: Artisan::output() is process-global state shared
        // with PendingCommand mocks and RefreshDatabase runs, so it is
        // order-dependent. An explicit buffer is deterministic.
        $buffer = new \Symfony\Component\Console\Output\BufferedOutput;
        $code = Artisan::call('inventory:audit-placements', array_merge(['--json' => true], $params), $buffer);
        $raw = $buffer->fetch();
        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, 'Audit --json output must decode to an array. Raw head: '.substr($raw, 0, 500));

        return ['exit' => $code, 'data' => $decoded];
    }

    private function checkOf(array $data, string $key): array
    {
        foreach ($data['checks'] as $check) {
            if ($check['key'] === $key) {
                return $check;
            }
        }
        $this->fail("Check {$key} missing from audit output.");
    }

    public function test_clean_state_exits_zero(): void
    {
        $p = $this->simpleProduct(10);
        $loc = $this->location('AUD-CLEAN');

        ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => null,
            'location_id' => $loc->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 10, 'allocated_hint' => 0,
        ]);

        $this->artisan('inventory:audit-placements')->assertExitCode(0);
    }

    public function test_aggregate_drift_is_reported(): void
    {
        $p = $this->simpleProduct(10);
        $loc = $this->location('AUD-DRIFT');

        ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => null,
            'location_id' => $loc->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 4, 'allocated_hint' => 0,
        ]);

        $this->artisan('inventory:audit-placements', ['--json' => true])
            ->assertExitCode(1);

        $this->artisan('inventory:audit-placements')
            ->expectsOutputToContain('aggregate_vs_locations')
            ->assertExitCode(1);
    }

    public function test_inactive_bin_and_hint_overflow_are_reported(): void
    {
        $p = $this->simpleProduct(5);
        $dead = $this->location('AUD-DEAD', Location::STATUS_INACTIVE);

        ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => null,
            'location_id' => $dead->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 5, 'allocated_hint' => 0,
        ]);

        $live = $this->location('AUD-HINT');
        ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => null,
            'location_id' => $live->id, 'warehouse_id' => $this->warehouse->id,
            'quantity' => 2, 'allocated_hint' => 0,
        ]);

        // Defense-in-depth proof: the DB CHECK refuses hint > quantity even
        // for out-of-band writes, so the audit's hint_exceeds_placement check
        // is a backstop (e.g. other drivers / legacy rows), not a live path.
        // CHECKs exist on MySQL only (see create_product_locations migration);
        // other drivers must observe the audit backstop instead.
        if (\Illuminate\Support\Facades\Schema::getConnection()->getDriverName() === 'mysql') {
            try {
                ProductLocation::where('location_id', $live->id)->update(['allocated_hint' => 5]);
                $this->fail('Expected CHECK constraint to refuse allocated_hint > quantity.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('3819', $e->getMessage());
            }
        } else {
            ProductLocation::where('location_id', $live->id)->update(['allocated_hint' => 5]);
            $this->artisan('inventory:audit-placements')
                ->expectsOutputToContain('hint_exceeds_placement')
                ->assertExitCode(1);
            ProductLocation::where('location_id', $live->id)->update(['allocated_hint' => 0]);
        }

        $this->artisan('inventory:audit-placements')
            ->expectsOutputToContain('live_hints_on_inactive_bins')
            ->assertExitCode(1);

        // Report-only proof: the rows are untouched by the audit.
        $this->assertSame(5.0, (float) ProductLocation::where('location_id', $dead->id)->firstOrFail()->quantity);
        $this->assertSame(5, (int) $p->fresh()->stock_quantity);
    }

    public function test_warehouse_scope_narrows_audit(): void
    {
        $other = Warehouse::create(['code' => 'WH-AUD-B', 'name' => 'Audit B', 'status' => 'active', 'is_default' => false]);
        $otherLoc = Location::create([
            'warehouse_id' => $other->id, 'code' => 'AUD-OTHER', 'name' => 'Other',
            'type' => Location::TYPE_STORAGE, 'status' => Location::STATUS_ACTIVE,
        ]);
        $p = $this->simpleProduct(7);
        ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => null,
            'location_id' => $otherLoc->id, 'warehouse_id' => $other->id,
            'quantity' => 1, 'allocated_hint' => 0,
        ]);

        // Scoped to the clean home warehouse: no findings (the global
        // aggregate check is skipped under scope — see command docblock).
        $this->artisan('inventory:audit-placements', ['--warehouse' => $this->warehouse->id])->assertExitCode(0);
        // Unscoped: the other warehouse's drift surfaces.
        $this->artisan('inventory:audit-placements')->assertExitCode(1);
    }

    public function test_invalid_warehouse_scope_is_rejected(): void
    {
        $this->artisan('inventory:audit-placements', ['--warehouse' => 'abc'])->assertExitCode(2);
        $this->artisan('inventory:audit-placements', ['--warehouse' => '0'])->assertExitCode(2);
        $this->artisan('inventory:audit-placements', ['--warehouse' => '999999'])->assertExitCode(2);
    }

    public function test_zero_stock_without_placement_is_clean(): void
    {
        $this->simpleProduct(0);
        [$p, $a] = $this->variableProductWithVariants(0, 0);

        $result = $this->jsonAudit();
        $this->assertSame(0, $result['exit']);
        $this->assertSame('clean', $result['data']['status']);
        $this->assertSame(0, $this->checkOf($result['data'], 'unplaced_stock')['count']);
    }

    public function test_unplaced_simple_stock_is_reported(): void
    {
        $p = $this->simpleProduct(9);

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $this->assertSame('findings', $result['data']['status']);
        $unplaced = $this->checkOf($result['data'], 'unplaced_stock');
        $this->assertSame('findings', $unplaced['status']);
        $this->assertSame(1, $unplaced['count']);
        $this->assertSame($p->id, $unplaced['samples'][0]['id']);
        // The placement-group drift check must stay silent: it cannot see this SKU.
        $this->assertSame(0, $this->checkOf($result['data'], 'aggregate_vs_locations')['count']);
    }

    public function test_unplaced_variant_stock_is_reported(): void
    {
        [$p, $a, $b] = $this->variableProductWithVariants(6, 0);

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $unplaced = $this->checkOf($result['data'], 'unplaced_stock');
        $this->assertSame(1, $unplaced['count']);
        $this->assertSame('variant', $unplaced['samples'][0]['type']);
        $this->assertSame($a->id, $unplaced['samples'][0]['variant_id']);
    }

    public function test_matching_multi_placement_state_is_clean(): void
    {
        [$p, $a, $b] = $this->variableProductWithVariants(10, 0);
        $l1 = $this->location('AUD-M1');
        $l2 = $this->location('AUD-M2');
        $this->place($p->id, $l1->id, 6, $a->id);
        $this->place($p->id, $l2->id, 4, $a->id);

        $result = $this->jsonAudit();
        $this->assertSame(0, $result['exit'], json_encode($result['data']));
        $this->assertSame('clean', $result['data']['status']);
    }

    public function test_excess_placement_is_reported(): void
    {
        $p = $this->simpleProduct(10);
        $this->place($p->id, $this->location('AUD-EX')->id, 14);

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $drift = $this->checkOf($result['data'], 'aggregate_vs_locations');
        $this->assertSame(1, $drift['count']);
        $this->assertEqualsWithDelta(14.0, (float) $drift['samples'][0]['placed_sum'], 0.0001);
    }

    public function test_wrong_parent_placement_is_reported(): void
    {
        [$pA] = $this->variableProductWithVariants();
        [$pB, $bVar] = $this->variableProductWithVariants();
        // Variant of B pinned under product A's identity: FKs allow it, audit must catch it.
        $this->place($pA->id, $this->location('AUD-WP')->id, 3, $bVar->id);

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $mm = $this->checkOf($result['data'], 'variant_parent_mismatch');
        $this->assertSame(1, $mm['count']);
        $this->assertSame($pB->id, $mm['samples'][0]['variant_parent_id']);
    }

    public function test_duplicate_simple_rows_are_reported(): void
    {
        // MySQL UNIQUE permits multiple NULL-variant rows: the app lock is the
        // only guard, so this check is live rather than theoretical.
        $p = $this->simpleProduct(6);
        $loc = $this->location('AUD-DUP');
        $this->place($p->id, $loc->id, 3);
        $this->place($p->id, $loc->id, 3);

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $dup = $this->checkOf($result['data'], 'duplicate_sku_rows');
        $this->assertSame(1, $dup['count']);
        $this->assertSame(2, (int) $dup['samples'][0]['dup_rows']);
        // Placement sum still matches authority: only the duplicate check fires.
        $this->assertSame(0, $this->checkOf($result['data'], 'aggregate_vs_locations')['count']);
    }

    public function test_warehouse_drift_is_reported(): void
    {
        $other = Warehouse::create(['code' => 'WH-AUD-D', 'name' => 'Drift', 'status' => 'active', 'is_default' => false]);
        $p = $this->simpleProduct(5);
        $loc = $this->location('AUD-WD');
        ProductLocation::create([
            'product_id' => $p->id, 'product_variant_id' => null,
            'location_id' => $loc->id, 'warehouse_id' => $other->id,
            'quantity' => 5, 'allocated_hint' => 0,
        ]);

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $this->assertSame(1, $this->checkOf($result['data'], 'warehouse_drift')['count']);
    }

    public function test_invalid_refs_are_reported(): void
    {
        $p = $this->simpleProduct(0);
        $loc = $this->location('AUD-IR');

        Schema::disableForeignKeyConstraints();
        try {
            DB::table('product_locations')->insert([
                'product_id' => 999991, 'product_variant_id' => null, 'location_id' => $loc->id,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_locations')->insert([
                'product_id' => $p->id, 'product_variant_id' => 999992, 'location_id' => $loc->id,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_locations')->insert([
                'product_id' => $p->id, 'product_variant_id' => null, 'location_id' => 999993,
                'warehouse_id' => $this->warehouse->id, 'quantity' => 0, 'allocated_hint' => 0,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $invalid = $this->checkOf($result['data'], 'invalid_refs');
        $this->assertSame(3, $invalid['count']);
        $this->assertSame(['missing_location', 'missing_product', 'missing_variant'], collect($invalid['samples'])->pluck('type')->sort()->values()->all());
    }

    public function test_non_placeable_type_bin_is_reported(): void
    {
        $p = $this->simpleProduct(4);
        $q = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'AUD-Q', 'name' => 'Quarantine',
            'type' => Location::TYPE_QUARANTINE, 'status' => Location::STATUS_ACTIVE,
        ]);
        $this->place($p->id, $q->id, 4);

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $this->assertSame(1, $this->checkOf($result['data'], 'live_hints_on_inactive_bins')['count']);
    }

    public function test_digital_product_rules(): void
    {
        $d = $this->simpleProduct(5);
        $d->forceFill(['item_type' => ItemType::DIGITAL])->save();

        // Digital stock without placements is excluded from unplaced_stock.
        $clean = $this->jsonAudit();
        $this->assertSame(0, $clean['exit']);
        $this->assertSame(0, $this->checkOf($clean['data'], 'unplaced_stock')['count']);

        // A live placement on a digital product is a finding.
        $this->place($d->id, $this->location('AUD-DIG')->id, 5);
        $dirty = $this->jsonAudit();
        $this->assertSame(1, $dirty['exit']);
        $this->assertSame(1, $this->checkOf($dirty['data'], 'non_physical_placements')['count']);
    }

    public function test_soft_deleted_product_is_excluded_from_unplaced(): void
    {
        $p = $this->simpleProduct(5);
        $p->delete();

        $result = $this->jsonAudit();
        $this->assertSame(0, $result['exit']);
        $this->assertSame(0, $this->checkOf($result['data'], 'unplaced_stock')['count']);
    }

    public function test_negative_quantity_refused_by_constraint(): void
    {
        $p = $this->simpleProduct(0);
        $loc = $this->location('AUD-NEG');

        if (DB::getDriverName() === 'mysql') {
            try {
                $this->place($p->id, $loc->id, -1);
                $this->fail('Expected CHECK constraint to refuse negative quantity.');
            } catch (\Illuminate\Database\QueryException $e) {
                $this->assertStringContainsString('3819', $e->getMessage());
            }
        } else {
            $this->markTestSkipped('MySQL CHECK behavior; audit backstop covered by other drivers in hint test.');
        }

        $this->assertSame(0, $this->jsonAudit()['exit']);
    }

    public function test_json_contract_is_stable(): void
    {
        $p = $this->simpleProduct(10);
        $this->place($p->id, $this->location('AUD-JS')->id, 10);

        $result = $this->jsonAudit();
        $data = $result['data'];
        $this->assertSame(0, $result['exit']);
        $this->assertSame('clean', $data['status']);
        $this->assertSame('global', $data['scope']);
        $this->assertNull($data['warehouse_id']);
        $this->assertSame(10, count($data['checks']));
        $this->assertSame(10, $data['status_counts']['clean']);
        $this->assertSame(0, $data['total_findings']);
        $this->assertNotEmpty($data['total_findings_note']);
        foreach ($data['checks'] as $check) {
            $this->assertArrayHasKey('key', $check);
            $this->assertArrayHasKey('status', $check);
            $this->assertArrayHasKey('count', $check);
            $this->assertArrayHasKey('message', $check);
            $this->assertArrayHasKey('samples', $check);
            $this->assertSame('clean', $check['status']);
        }
    }

    public function test_scoped_json_marks_global_checks_skipped(): void
    {
        $p = $this->simpleProduct(7);
        $this->place($p->id, $this->location('AUD-SJ')->id, 7);

        $result = $this->jsonAudit(['--warehouse' => $this->warehouse->id]);
        $this->assertSame(0, $result['exit']);
        // Scoped runs cannot claim global validation: overall is clean only
        // because every *applicable* check ran; skips are explicit.
        $this->assertSame('clean', $result['data']['status']);
        $this->assertSame("warehouse:{$this->warehouse->id}", $result['data']['scope']);
        $this->assertSame('skipped', $this->checkOf($result['data'], 'aggregate_vs_locations')['status']);
        $this->assertSame('skipped', $this->checkOf($result['data'], 'unplaced_stock')['status']);
        $this->assertSame(2, $result['data']['status_counts']['skipped']);
        $this->assertSame(8, $result['data']['status_counts']['clean']);
    }

    public function test_failing_check_becomes_error_not_clean(): void
    {
        $probe = new class extends \App\Console\Commands\AuditInventoryPlacements
        {
            public function probe(string $key, \Closure $c): array
            {
                return $this->runCheck($key, $c);
            }
        };

        $result = $probe->probe('boom', fn () => throw new \RuntimeException('snap'));
        $this->assertSame('boom', $result['key']);
        $this->assertSame('error', $result['status']);
        $this->assertSame(0, $result['count']);
        $this->assertNotEmpty($result['message']);
    }

    public function test_audit_writes_nothing(): void
    {
        // Findings present on purpose: the audit must report without repairing.
        $drifted = $this->simpleProduct(10);
        $this->place($drifted->id, $this->location('AUD-RO1')->id, 4);
        $unplaced = $this->simpleProduct(3);
        $dead = $this->location('AUD-RO2', Location::STATUS_INACTIVE);
        $this->place($drifted->id, $dead->id, 1);

        $snapshot = $this->captureInventoryState();
        $this->assertSame(1, $this->jsonAudit()['exit']);
        $this->assertSame($snapshot, $this->captureInventoryState());

        // Source-level guard: the command must contain no write operations.
        $source = file_get_contents(app_path('Console/Commands/AuditInventoryPlacements.php'));
        foreach (['->insert(', '->update(', '->delete(', '::create(', '->decrement(', '->increment(', '->upsert(', 'DB::statement('] as $write) {
            $this->assertStringNotContainsString($write, $source, "Command source must stay read-only: found {$write}");
        }
    }

    public function test_sample_cap_keeps_full_count(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $p = $this->simpleProduct(2);
            $loc = $this->location('AUD-CAP'.$i);
            $this->place($p->id, $loc->id, 1);
            $this->place($p->id, $loc->id, 1);
        }

        $result = $this->jsonAudit();
        $this->assertSame(1, $result['exit']);
        $dup = $this->checkOf($result['data'], 'duplicate_sku_rows');
        $this->assertSame(12, $dup['count']);
        $this->assertCount(10, $dup['samples']);
    }

    public function test_scale_three_hundred_placements(): void
    {
        for ($i = 0; $i < 300; $i++) {
            $p = $this->simpleProduct(1);
            $this->place($p->id, $this->location('AUD-SC'.$i)->id, 1);
        }

        $started = microtime(true);
        $result = $this->jsonAudit();
        $elapsed = microtime(true) - $started;

        $this->assertSame(0, $result['exit'], json_encode(array_slice($result['data']['checks'], 0, 3)));
        $this->assertLessThan(60, $elapsed);
    }

    /** @return array<string,mixed> */
    private function captureInventoryState(): array
    {
        return [
            'products' => DB::table('products')->count(),
            'products_stock' => (float) DB::table('products')->sum('stock_quantity'),
            'products_reserved' => (float) DB::table('products')->sum('reserved_quantity'),
            'variants' => DB::table('product_variants')->count(),
            'variants_stock' => (float) DB::table('product_variants')->sum('stock_quantity'),
            'placements' => DB::table('product_locations')->count(),
            'placed_qty' => (float) DB::table('product_locations')->sum('quantity'),
            'placed_hint' => (float) DB::table('product_locations')->sum('allocated_hint'),
            'operations' => DB::table('inventory_operations')->count(),
            'orders' => DB::table('orders')->count(),
        ];
    }
}
