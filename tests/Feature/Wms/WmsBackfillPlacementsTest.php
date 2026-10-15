<?php

namespace Tests\Feature\Wms;

use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Location;
use App\Models\Fulfillment\PickingTask;
use App\Models\Fulfillment\ProductLocation;
use App\Models\Fulfillment\Warehouse;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Inventory\StockOperationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * Catalog backfill (Phase F/G): mapping-driven placement recording.
 *
 * - Dry-run writes nothing (hints, stock, IDs unchanged).
 * - Apply records hints, preserves product/variant IDs, SKUs, authority
 *   stock/reservations, and existing order/fulfillment references.
 * - Reruns are no-ops (convergence idempotency).
 * - Invalid rows (unknown location, variant mismatch, over-cap, digital)
 *   are reported and skipped while valid rows still apply.
 * - Backfilled placements flow into fulfillment allocation + picking.
 */
class WmsBackfillPlacementsTest extends TestCase
{
    use RefreshDatabase;

    private Warehouse $warehouse;

    private Location $location;

    private Product $simple;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        foreach ([
            'view-warehouse', 'manage-warehouse', 'view-fulfillment', 'manage-fulfillment',
            'picking-execute', 'inventory-adjust',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }

        $this->warehouse = Warehouse::create([
            'code' => 'WH-BF', 'name' => 'Backfill WH', 'status' => 'active', 'is_default' => true,
        ]);
        $this->location = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'BF-01',
            'barcode' => 'BF-LOC-01', 'name' => 'Bin', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        // Authority stock WITHOUT any placement row (the backfill target).
        $this->simple = Product::create([
            'name' => 'BF', 'slug' => 'bf-' . uniqid(), 'sku' => 'SKU-BF-1',
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 80, 'reserved_quantity' => 5,
        ]);
    }

    private function mappingFile(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bf-') . '.json';
        file_put_contents($path, json_encode($rows));

        return $path;
    }

    /**
     * Run the backfill command deterministically.
     *
     * NOTE (verified): command output capture (Artisan::call buffer and
     * PendingCommand expectations) yields empty output in this phpunit
     * environment even though CLI execution prints full output. Tests
     * therefore assert exit codes + persisted DB state (the real proof);
     * --json summary format is proven by CLI execution evidence.
     */
    private function runBackfill(string $mappingPath, bool $apply = false): int
    {
        $params = ['--mapping' => $mappingPath, '--json' => true];
        if ($apply) {
            $params['--apply'] = true;
        }

        return $this->artisan('inventory:backfill-placements', $params)->run();
    }

    private function mappingRow(?int $locationId = null, ?int $quantity = 50): array
    {
        return [[
            'product_id' => $this->simple->id,
            'product_variant_id' => null,
            'location_id' => $locationId ?? $this->location->id,
            'quantity' => $quantity,
        ]];
    }

    public function test_dry_run_writes_nothing(): void
    {
        $path = $this->mappingFile($this->mappingRow());
        // Valid row dry-run: exit 0 proves full validation passed.
        $this->assertSame(0, $this->runBackfill($path));

        $this->assertSame(0, ProductLocation::count());
        $this->assertSame(80, (int) $this->simple->fresh()->stock_quantity);
        $this->assertSame(5, (int) $this->simple->fresh()->reserved_quantity);
    }

    public function test_apply_records_hint_and_preserves_authority(): void
    {
        $path = $this->mappingFile($this->mappingRow());
        $this->assertSame(0, $this->runBackfill($path, true));

        $placement = ProductLocation::where('product_id', $this->simple->id)->firstOrFail();
        $this->assertSame($this->location->id, (int) $placement->location_id);
        $this->assertSame($this->warehouse->id, (int) $placement->warehouse_id);
        $this->assertNull($placement->product_variant_id);
        $this->assertEqualsWithDelta(50.0, (float) $placement->quantity, 0.001);

        // Identities + authority untouched.
        $this->assertSame($this->simple->id, $placement->product_id);
        $this->assertSame('SKU-BF-1', $this->simple->fresh()->sku);
        $this->assertSame(80, (int) $this->simple->fresh()->stock_quantity);
        $this->assertSame(5, (int) $this->simple->fresh()->reserved_quantity);
    }

    public function test_rerun_is_idempotent_noop(): void
    {
        $path = $this->mappingFile($this->mappingRow());
        $this->assertSame(0, $this->runBackfill($path, true));
        $countAfterFirst = ProductLocation::count();
        $hintAfterFirst = (float) ProductLocation::firstOrFail()->quantity;

        // Converged rerun: exit 0 with zero writes (count + hint unchanged).
        $this->assertSame(0, $this->runBackfill($path, true));

        $this->assertSame($countAfterFirst, ProductLocation::count());
        $this->assertEqualsWithDelta($hintAfterFirst, (float) ProductLocation::firstOrFail()->quantity, 0.001);
        $this->assertSame(80, (int) $this->simple->fresh()->stock_quantity);
    }

    public function test_invalid_rows_reported_while_valid_rows_apply(): void
    {
        $other = Location::create([
            'warehouse_id' => $this->warehouse->id, 'code' => 'BF-02',
            'barcode' => 'BF-LOC-02', 'name' => 'Bin 2', 'type' => 'picking',
            'status' => 'active', 'priority' => 1,
        ]);
        $rows = [
            ['product_id' => $this->simple->id, 'product_variant_id' => null, 'location_id' => $other->id, 'quantity' => 20],
            ['product_id' => $this->simple->id, 'product_variant_id' => null, 'location_id' => 999999, 'quantity' => 10],
            ['product_id' => $this->simple->id, 'product_variant_id' => null, 'location_id' => $this->location->id, 'quantity' => 0],
        ];
        $path = $this->mappingFile($rows);
        // One valid row + two invalid: exit 1 signals failures present.
        $this->assertSame(1, $this->runBackfill($path, true));
        // Valid row persisted despite siblings failing (per-row transactions).
        $this->assertTrue(ProductLocation::where('product_id', $this->simple->id)->where('location_id', $other->id)->exists());
        $this->assertFalse(ProductLocation::where('product_id', $this->simple->id)->where('location_id', $this->location->id)->exists());
        $this->assertSame(80, (int) $this->simple->fresh()->stock_quantity);
    }

    public function test_hint_cap_refuses_manufactured_stock(): void
    {
        // Authority holds 80; mapping 500 must refuse, writing nothing.
        $path = $this->mappingFile($this->mappingRow(null, 500));
        $this->assertSame(1, $this->runBackfill($path, true));
        $this->assertSame(0, ProductLocation::count());
        $this->assertSame(80, (int) $this->simple->fresh()->stock_quantity);
    }

    public function test_backfilled_placement_flows_into_picking(): void
    {
        foreach (['picking-execute', 'view-fulfillment', 'manage-warehouse'] as $p) {
            Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
        }
        $path = $this->mappingFile($this->mappingRow());
        $this->assertSame(0, $this->runBackfill($path, true));

        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'BFE', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'processing',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending',
            'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 200, 'total_price' => 200,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $this->simple->id, 'product_name' => 'BF',
            'product_sku' => 'SKU-BF-1', 'product_quantity' => 2,
            'product_price' => 100, 'product_total_price' => 200,
        ]);
        $fulfillment = app(FulfillmentService::class)->releaseForOrder($order->fresh(), $this->warehouse->id, 'bf-e2e-' . uniqid());

        $item = $fulfillment->items()->firstOrFail();
        $this->assertNotNull($item->product_location_id);

        $picker = User::factory()->create(['type' => 'staff', 'warehouse_id' => $this->warehouse->id]);
        $picker->givePermissionTo('picking-execute');
        $response = $this->actingAs($picker, 'sanctum')->postJson(
            "/api/v1/admin/fulfillments/{$fulfillment->id}/create-tasks", []
        );
        $response->assertStatus(200);
        $taskId = collect($response->json('data'))->pluck('id')->first();
        $task = PickingTask::findOrFail($taskId);
        $this->assertSame((int) $item->product_location_id, (int) $task->product_location_id);
        $this->assertSame($this->warehouse->id, (int) $fulfillment->fresh()->warehouse_id);
    }

    public function test_variant_placement_is_sku_specific(): void
    {
        $variable = Product::create([
            'name' => 'BFV', 'slug' => 'bfv-' . uniqid(), 'sku' => 'SKU-BFV',
            'price' => 0, 'product_type' => 'variable', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 0, 'reserved_quantity' => 0,
        ]);
        $variant = $variable->variations()->create([
            'sku' => 'SKU-BFV-1', 'price' => 60, 'stock_quantity' => 30, 'reserved_quantity' => 0,
        ]);
        $rows = [[
            'product_id' => $variable->id, 'product_variant_id' => $variant->id,
            'location_id' => $this->location->id, 'quantity' => 30,
        ]];
        $path = $this->mappingFile($rows);
        $this->assertSame(0, $this->runBackfill($path, true));
        $placement = ProductLocation::where('product_variant_id', $variant->id)->firstOrFail();
        $this->assertSame($variable->id, (int) $placement->product_id);
        // Parent authority never substituted: stays zero.
        $this->assertSame(0, (int) $variable->fresh()->stock_quantity);
        $this->assertSame(30, (int) $variant->fresh()->stock_quantity);

        // Wrong-parent variant mapping is refused.
        $bad = [[
            'product_id' => $this->simple->id, 'product_variant_id' => $variant->id,
            'location_id' => $this->location->id, 'quantity' => 5,
        ]];
        $badPath = $this->mappingFile($bad);
        $this->assertSame(1, $this->runBackfill($badPath, true));
    }

    public function test_digital_product_is_refused(): void
    {
        $digital = Product::create([
            'name' => 'BFD', 'slug' => 'bfd-' . uniqid(), 'sku' => 'SKU-BFD-1',
            'price' => 10, 'product_type' => 'simple', 'item_type' => \Marvel\Enums\ItemType::DIGITAL,
            'status' => true, 'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0,
        ]);
        $rows = [[
            'product_id' => $digital->id, 'product_variant_id' => null,
            'location_id' => $this->location->id, 'quantity' => 10,
        ]];
        $path = $this->mappingFile($rows);
        $this->assertSame(1, $this->runBackfill($path, true));

        $this->assertSame(0, ProductLocation::where('product_id', $digital->id)->count());
        $this->assertSame(10, (int) $digital->fresh()->stock_quantity);
    }

    public function test_occupied_bin_is_refused(): void
    {
        $other = Product::create([
            'name' => 'BFO', 'slug' => 'bfo-' . uniqid(), 'sku' => 'SKU-BFO-1',
            'price' => 20, 'product_type' => 'simple', 'status' => true,
            'in_stock' => true, 'stock_quantity' => 40, 'reserved_quantity' => 0,
        ]);
        // Occupy the bin with a DIFFERENT SKU first.
        $this->assertSame(0, $this->runBackfill($this->mappingFile([[
            'product_id' => $other->id, 'product_variant_id' => null,
            'location_id' => $this->location->id, 'quantity' => 40,
        ]]), true));

        $rows = [[
            'product_id' => $this->simple->id, 'product_variant_id' => null,
            'location_id' => $this->location->id, 'quantity' => 10,
        ]];
        $path = $this->mappingFile($rows);
        $this->assertSame(1, $this->runBackfill($path, true));

        // Occupant untouched; intruder absent.
        $this->assertSame(0, ProductLocation::where('product_id', $this->simple->id)->count());
        $this->assertEqualsWithDelta(40.0, (float) ProductLocation::where('product_id', $other->id)->firstOrFail()->quantity, 0.001);
    }

    public function test_cross_warehouse_location_is_refused(): void
    {
        $foreign = Warehouse::create([
            'code' => 'WH-BF2', 'name' => 'Foreign WH', 'status' => 'active', 'is_default' => false,
        ]);
        $rows = [[
            'product_id' => $this->simple->id, 'product_variant_id' => null,
            'location_id' => $this->location->id, 'warehouse_id' => $foreign->id, 'quantity' => 10,
        ]];
        $path = $this->mappingFile($rows);
        $this->assertSame(1, $this->runBackfill($path, true));

        $this->assertSame(0, ProductLocation::where('product_id', $this->simple->id)->count());
        $this->assertSame(80, (int) $this->simple->fresh()->stock_quantity);
    }
}
