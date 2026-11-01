<?php

namespace Tests\Feature\Wms;

use App\Events\OrderCreated;
use App\Events\PaymentSucceeded;
use App\Listeners\ReleaseFulfillmentOnCodPlacement;
use App\Listeners\ReleaseFulfillmentOnPayment;
use App\Models\Fulfillment\Fulfillment;
use App\Models\Fulfillment\Warehouse;
use App\Models\Shipment;
use App\Services\Fulfillment\FulfillmentService;
use App\Services\Fulfillment\FulfillmentTransition;
use App\Services\General\OrderService;
use App\Services\Shipment\ShipmentService;
use App\Services\Warehouse\WarehouseWorkflowSwitchService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * WAREHOUSE-WORKFLOW-SWITCH — WAREHOUSE-WORKFLOW-OFF behavioral contract.
 *
 * Business rule: when the workflow is OFF the system runs in Orders-only
 * mode — orders keep their normal lifecycle, but NO new fulfillment can be
 * created or released (direct service, HTTP manual release single/bulk,
 * automatic listeners, hourly sweeper, master-config override). Existing
 * fulfillments keep operating through their ORIGINAL lifecycle (picking →
 * … → delivery) and the existing delivery-completion rules stay intact;
 * nothing is auto-delivered, faked, or rewritten because of the switch.
 *
 * MySQL-only, sequential (shared MySQL), RefreshDatabase (real migrations) —
 * same environment contract as WarehouseWorkflowTest.
 */
class WarehouseWorkflowSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        foreach ([
            'view-settings', 'update-settings',
            'manage-warehouse', 'fulfillment.create',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
        // array cache driver persists per process — isolate the switch key.
        Cache::forget(WarehouseWorkflowSwitchService::CACHE_KEY);
    }

    protected function tearDown(): void
    {
        config(['warehouse.workflow_enabled' => true]);
        Cache::forget(WarehouseWorkflowSwitchService::CACHE_KEY);
        parent::tearDown();
    }

    // ---------- helpers ----------

    /**
     * Stored admin tier (tier 2). Tier 1 (config) is flipped inline via
     * config([...]) — runtime-representative of env/config authority.
     */
    private function setStoredToggle(bool $enabled): void
    {
        $row = Settings::query()->first();

        $options = $row ? ($row->options ?? []) : [];
        $options['warehouse'] = array_merge(
            (array) data_get($options, 'warehouse', []),
            ['workflow_enabled' => $enabled]
        );

        if ($row) {
            $row->update(['options' => $options]);
        } else {
            Settings::create([
                'site_name' => json_encode(['en' => 'Switch Test'], JSON_THROW_ON_ERROR),
                'options' => $options,
            ]);
        }

        Cache::forget(WarehouseWorkflowSwitchService::CACHE_KEY);
    }

    private function admin(array $perms = ['update-settings', 'view-settings']): User
    {
        $u = User::factory()->create(['type' => 'staff']);
        foreach ($perms as $p) {
            $u->givePermissionTo($p);
        }

        return $u;
    }

    private function releaseAdmin(): User
    {
        // manage-warehouse crosses warehouse scope (WarehouseAccess rule);
        // fulfillment.create is the POST /admin/fulfillments/release gate.
        return $this->admin(['fulfillment.create', 'manage-warehouse']);
    }

    private function warehouse(): Warehouse
    {
        return Warehouse::create([
            'code' => 'WH-'.uniqid(), 'name' => 'Main', 'status' => 'active', 'is_default' => true,
        ]);
    }

    private function product(): Product
    {
        return Product::create([
            'name' => 'PW', 'slug' => 'pw-'.uniqid(), 'sku' => 'SKU-W-'.uniqid(),
            'price' => 100, 'product_type' => 'simple', 'status' => true,
            'item_type' => 'PHYSICAL',
            'in_stock' => true, 'stock_quantity' => 100, 'reserved_quantity' => 0, 'sold_quantity' => 0,
        ]);
    }

    private function paidOrder(Product $product): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'WO', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS, 'payment_method' => 'online',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_COMMITTED,
            'price' => 100, 'total_price' => 100,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'PW',
            'product_sku' => $product->sku, 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
            'item_type' => 'PHYSICAL',
        ]);
        app(\App\Services\OrderFlow\OrderFlowService::class)->assignFlowToOrder($order->refresh(), 'local');

        return $order->refresh();
    }

    private function codOrder(Product $product): Order
    {
        $customer = User::factory()->create(['type' => 'customer']);
        $order = Order::create([
            'user_id' => $customer->id, 'name' => 'COD', 'user_email' => $customer->email,
            'user_phone' => '010', 'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING, 'payment_method' => 'cod',
            'fulfillment_status' => 'pending', 'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'price' => 100, 'total_price' => 100,
            'currency_code' => 'EGP', 'base_currency_code' => 'EGP',
            'fulfillment_type' => 'delivery', 'address' => ['city' => 'Cairo'],
        ]);
        $order->orderItems()->create([
            'product_id' => $product->id, 'product_name' => 'PW',
            'product_sku' => $product->sku, 'product_quantity' => 1,
            'product_price' => 100, 'product_total_price' => 100,
            'item_type' => 'PHYSICAL',
        ]);

        return $order->refresh();
    }

    // ---------- A. Workflow ON preserves existing behavior ----------

    public function test_on_payment_listener_releases_exactly_one_fulfillment(): void
    {
        $this->warehouse();
        $order = $this->paidOrder($this->product());

        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals('pending', $rows->first()->status);
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey((int) $order->id),
            $rows->first()->idempotency_key
        );
    }

    public function test_on_http_bulk_release_works(): void
    {
        $this->warehouse();
        $admin = $this->releaseAdmin();
        $o1 = $this->paidOrder($this->product());
        $o2 = $this->paidOrder($this->product());

        $res = $this->actingAs($admin, 'sanctum')->postJson('/api/v1/admin/fulfillments/release', [
            'order_ids' => [$o1->id, $o2->id],
        ]);

        $res->assertStatus(200);
        $this->assertCount(2, $res->json('data.released') ?? []);
        $this->assertEquals(2, Fulfillment::count());
    }

    // ---------- B. Workflow OFF blocks every NEW creation path ----------

    public function test_off_blocks_direct_service_release_and_creates_nothing(): void
    {
        $this->setStoredToggle(false);
        $this->warehouse();
        $order = $this->paidOrder($this->product());

        try {
            app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, 'fresh-key-'.uniqid());
            $this->fail('new release must be refused when the workflow is disabled');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Warehouse workflow is disabled', $e->getMessage());
        }

        $this->assertEquals(0, Fulfillment::count());
    }

    public function test_off_blocks_single_http_release(): void
    {
        $this->setStoredToggle(false);
        $this->warehouse();
        $admin = $this->releaseAdmin();
        $order = $this->paidOrder($this->product());

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/release', ['order_id' => $order->id])
            ->assertStatus(422);

        $this->assertEquals(0, Fulfillment::count());
    }

    public function test_off_blocks_bulk_http_release_without_partial_rows(): void
    {
        $this->setStoredToggle(false);
        $this->warehouse();
        $admin = $this->releaseAdmin();
        $o1 = $this->paidOrder($this->product());
        $o2 = $this->paidOrder($this->product());

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/v1/admin/fulfillments/release', ['order_ids' => [$o1->id, $o2->id]])
            ->assertStatus(422);

        // A rejected batch leaves no partial fulfillment records.
        $this->assertEquals(0, Fulfillment::where('order_id', $o1->id)->count());
        $this->assertEquals(0, Fulfillment::where('order_id', $o2->id)->count());
    }

    public function test_off_payment_listener_skips_without_rows(): void
    {
        $this->setStoredToggle(false);
        $this->warehouse();
        $order = $this->paidOrder($this->product());

        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order));

        $this->assertEquals(0, Fulfillment::count());
        $this->assertEquals(0, Shipment::count());
    }

    public function test_off_cod_listener_skips_without_rows(): void
    {
        $this->setStoredToggle(false);
        $this->warehouse();
        $order = $this->codOrder($this->product());

        app(ReleaseFulfillmentOnCodPlacement::class)->handle(new OrderCreated($order));

        $this->assertEquals(0, Fulfillment::count());
    }

    public function test_off_sweeper_suppresses_without_rows(): void
    {
        $this->setStoredToggle(false);
        $this->warehouse();
        $this->paidOrder($this->product()); // otherwise perfectly releasable

        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $this->assertEquals(0, Fulfillment::count());
    }

    public function test_master_config_off_blocks_even_when_stored_toggle_on(): void
    {
        // Background jobs / listeners cannot bypass the restriction even
        // when the admin runtime toggle wants the workflow enabled.
        $this->setStoredToggle(true);
        config(['warehouse.workflow_enabled' => false]);
        $this->warehouse();
        $order = $this->paidOrder($this->product());
        $cod = $this->codOrder($this->product());

        try {
            app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, null);
            $this->fail('master config false must refuse regardless of the admin toggle');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Warehouse workflow is disabled', $e->getMessage());
        }

        app(ReleaseFulfillmentOnPayment::class)->handle(new PaymentSucceeded($order->refresh()));
        app(ReleaseFulfillmentOnCodPlacement::class)->handle(new OrderCreated($cod));
        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $this->assertEquals(0, Fulfillment::count());
    }

    // ---------- C. Existing fulfillments continue ----------

    public function test_off_existing_fulfillment_runs_to_delivery(): void
    {
        $wh = $this->warehouse();
        $order = $this->paidOrder($this->product());
        $service = app(FulfillmentService::class);

        $f = $service->releaseForOrder($order, $wh->id, null); // created while ON
        $this->setStoredToggle(false);

        // Continuation through the authoritative transition owner.
        $t = app(FulfillmentTransition::class);
        $t->transition($f->fresh(), 'picking');
        $t->transition($f->fresh(), 'picked');
        $t->transition($f->fresh(), 'packing');
        $t->transition($f->fresh(), 'ready_to_ship');

        // Continue shipping: new shipment for the EXISTING fulfillment.
        $shipment = app(ShipmentService::class)->createForFulfillment($f->fresh(), [], 'cont-ship-'.uniqid());
        app(ShipmentService::class)->dispatch($shipment->id);
        $this->assertEquals('shipped', $f->fresh()->status);

        // Complete through the canonical order writer. PaymentSucceeded
        // fires here (emit default), falls on the disabled listener, and
        // must stay a silent policy skip.
        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        // Continue delivery through the EXISTING delivery process.
        app(ShipmentService::class)->markDelivered($shipment->id);

        $f->refresh();
        $this->assertEquals('delivered', $f->fresh()->status);
        $this->assertEquals('delivered', $order->fresh()->status);

        // No phantom rows: one fulfillment, one shipment.
        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
        $this->assertEquals(1, Shipment::where('order_id', $order->id)->count());
    }

    public function test_off_replay_of_existing_auto_key_converges_without_new_row(): void
    {
        $wh = $this->warehouse();
        $order = $this->paidOrder($this->product());
        $service = app(FulfillmentService::class);
        $key = FulfillmentService::automaticReleaseKey((int) $order->id);

        $f = $service->releaseForOrder($order, $wh->id, $key); // ON
        $this->setStoredToggle(false);

        $same = $service->releaseForOrder($order->refresh(), $wh->id, $key);

        // Reuse-before-create: the automatic replay returns the existing row
        // (continuation) instead of being refused as a creation attempt.
        $this->assertSame($f->id, $same->id);
        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---------- D. Safety: no fake completion, no data side effects ----------

    public function test_off_never_auto_delivers_or_completes_without_fulfillment(): void
    {
        $this->setStoredToggle(false);
        $order = $this->paidOrder($this->product()); // releasable otherwise

        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertNotEquals('delivered', $order->fresh()->status);
        $this->assertNotEquals('completed', $order->fresh()->status);
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
        $this->assertEquals(0, Shipment::where('order_id', $order->id)->count());
    }

    public function test_disabling_changes_never_touch_inventory_state(): void
    {
        $order = $this->paidOrder($this->product());
        $before = $order->fresh()->inventory_state;

        $this->setStoredToggle(false);

        try {
            app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, null);
            $this->fail('refusal expected');
        } catch (\RuntimeException) {
        }
        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $this->assertEquals($before, $order->fresh()->inventory_state);
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());
    }

    public function test_reensure_sweeper_releases_missing_order_exactly_once_after_reenable(): void
    {
        $this->warehouse();
        $this->setStoredToggle(false);
        $order = $this->paidOrder($this->product());
        $this->artisan('fulfillment:release-ready')->assertExitCode(0);
        $this->assertEquals(0, Fulfillment::where('order_id', $order->id)->count());

        $this->setStoredToggle(true);
        $this->artisan('fulfillment:release-ready')->assertExitCode(0);

        $rows = Fulfillment::where('order_id', $order->id)->get();
        $this->assertCount(1, $rows);
        $this->assertEquals(
            FulfillmentService::automaticReleaseKey((int) $order->id),
            $rows->first()->idempotency_key
        );

        // Idempotent: a second sweep adds nothing.
        $this->artisan('fulfillment:release-ready')->assertExitCode(0);
        $this->assertEquals(1, Fulfillment::where('order_id', $order->id)->count());
    }

    // ---------- E. Switch endpoint auth/permissions/validation ----------

    public function test_status_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/v1/admin/warehouse-workflow/status')->assertStatus(401);
        $this->putJson('/api/v1/admin/warehouse-workflow/status', ['workflow_enabled' => false])->assertStatus(401);
    }

    public function test_status_endpoint_enforces_settings_permissions(): void
    {
        // Bootstrap the settings row: a PUT without it fails loudly (repo
        // convention — writers presume the seeded singleton row).
        $this->setStoredToggle(true);
        $viewer = $this->admin(['view-settings']);
        $writer = $this->admin(['update-settings']);

        $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/warehouse-workflow/status')->assertStatus(200);
        $this->actingAs($viewer, 'sanctum')
            ->putJson('/api/v1/admin/warehouse-workflow/status', ['workflow_enabled' => false])->assertStatus(403);

        $this->actingAs($writer, 'sanctum')
            ->getJson('/api/v1/admin/warehouse-workflow/status')->assertStatus(403);
        $this->actingAs($writer, 'sanctum')
            ->putJson('/api/v1/admin/warehouse-workflow/status', ['workflow_enabled' => false])->assertStatus(200);
    }

    public function test_status_update_validates_payload_and_switches_effectively(): void
    {
        $this->setStoredToggle(true);
        $admin = $this->admin(['update-settings', 'view-settings']);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/warehouse-workflow/status', [])
            ->assertStatus(422);
        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/warehouse-workflow/status', ['workflow_enabled' => 'not-boolean'])
            ->assertStatus(422);

        $this->actingAs($admin, 'sanctum')
            ->putJson('/api/v1/admin/warehouse-workflow/status', ['workflow_enabled' => false])
            ->assertStatus(200)
            ->assertJsonPath('data.effective_enabled', false);
        $this->assertEquals(
            false,
            Settings::query()->first()->options['warehouse']['workflow_enabled']
        );

        // Instant effect on the release boundary (no deploy step needed).
        $this->warehouse();
        $order = $this->paidOrder($this->product());
        try {
            app(FulfillmentService::class)->releaseForOrder($order->refresh(), null, null);
            $this->fail('expected refusal after turning the switch off');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('Warehouse workflow is disabled', $e->getMessage());
        }
    }

    public function test_status_reads_defaults_when_settings_row_is_missing(): void
    {
        $viewer = $this->admin(['view-settings']);

        $res = $this->actingAs($viewer, 'sanctum')
            ->getJson('/api/v1/admin/warehouse-workflow/status')
            ->assertStatus(200);

        $this->assertTrue($res->json('data.config_enabled'));
        $this->assertTrue($res->json('data.effective_enabled'));
        $this->assertTrue($res->json('data.readable'));
    }
}
