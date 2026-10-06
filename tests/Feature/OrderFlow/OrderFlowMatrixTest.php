<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use App\Services\OrderFlow\OrderFlowService;
use Database\Seeders\OrderFlowSeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Global catalog verification: transition matrix per active flow,
 * cross-flow invariants, seeder idempotency/non-destruction, default
 * uniqueness, ENUM/service parity, and stale-state concurrency.
 */
class OrderFlowMatrixTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const CHECKOUT_PREFIX = '/api/v1/general';

    private User $user;

    private User $admin;

    private Product $product;

    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();

        $this->createAllTestTables();

        // History table mirrors the production migration; absent from the
        // shared trait, so history writes are skipped without it.
        if (!Schema::hasTable('order_status_history')) {
            Schema::create('order_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->string('old_payment_status')->nullable();
                $table->string('new_payment_status')->nullable();
                $table->string('old_fulfillment_status')->nullable();
                $table->string('new_fulfillment_status')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('changed_by_type')->default('user');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();
            });
        }

        config(['payment.order_timeout_hours' => 24]);
        config(['payment.cod_order_timeout_hours' => 24 * 7]);
        config(['payment.default_currency' => 'EGP']);
        config(['shop.default_currency' => 'EGP']);

        if (!Settings::exists()) {
            Settings::create([
                'language' => 'en',
                'options' => ['catalog_currency_code' => 'EGP', 'base_currency_code' => 'EGP', 'currency' => 'EGP'],
                'minimum_order_amount' => 0]);
        }

        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Gov',
            'status' => true]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 0,
            'status' => true]);

        $this->user = User::create([
            'name' => 'Buyer',
            'email' => 'buyer@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);
        if (Schema::hasTable('permissions')) {
            foreach (['update-order-status', 'payments.mark_paid', 'view-orders', 'view-order'] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->product = Product::create([
            'name' => 'Product A',
            'slug' => 'product-a-' . Str::random(6),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
            'reserved_quantity' => 0,
            'sold_quantity' => 0]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function freshCartWithProduct(User $user): void
    {
        $cart = Cart::firstOrCreate(
            ['user_id' => $user->id, 'status' => 'active'],
            ['total_price' => 0, 'coupon' => null]
        );
        $cart->items()->delete();
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => $this->product->price,
            'total_price' => $this->product->price,
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false]);
        $cart->update(['total_price' => $this->product->price]);
    }

    private function checkoutOrder(?string $shippingType = null): Order
    {
        $this->freshCartWithProduct($this->user);
        Sanctum::actingAs($this->user);
        $payload = [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery'];
        if ($shippingType !== null) {
            $payload['shipping_type'] = $shippingType;
        }
        $this->postJson(self::CHECKOUT_PREFIX . '/checkout', $payload)->assertStatus(200);

        return Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
    }

    private function change(Order $order, string $to): void
    {
        app(\App\Services\General\OrderService::class)->changeOrderStatus(null, $to, $order->id);
    }

    private function assertInvariants(Order $order): void
    {
        $order->refresh();
        $order->load(['flow', 'currentStatus']);
        $this->assertNotNull($order->flow_id);
        $this->assertNotNull($order->current_status_id);
        $this->assertSame($order->flow->shipping_type, $order->shipping_type);
        $this->assertSame($order->status, $order->currentStatus->code);
        // current status belongs to the assigned flow (milestones live in the
        // catalog; flow steps additionally appear in the flow ordering).
        $this->assertTrue(
            $order->flow->statuses()->where('order_statuses.id', $order->current_status_id)->exists()
            || in_array($order->status, ['completed', 'cancelled', 'failed_delivery', 'returned'], true),
            "Status {$order->status} is neither a flow step nor a supervised exit."
        );
    }

    // -----------------------------------------------------------------
    // Matrix: local flow full walk
    // -----------------------------------------------------------------

    public function test_local_flow_full_walk_and_invariants(): void
    {
        $order = $this->checkoutOrder();

        foreach (['processing', 'packed', 'shipped', 'out_for_delivery'] as $step) {
            $this->change($order, $step);
            $this->assertInvariants($order);
        }

        // Phase 8 (D8-5): the normal delivered step now requires the
        // shipment completion invariant (paid + all fulfillments delivered),
        // which a bare checkout order does not satisfy.
        try {
            $this->change($order, 'delivered');
            $this->fail('Normal delivered without the completion invariant must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('every fulfillment delivered', $e->getMessage());
        }
        $this->assertSame('out_for_delivery', $order->fresh()->status);

        // The audited force path preserves the flow tail.
        $this->forceDeliver($order);

        $this->assertSame('delivered', $order->fresh()->status);
        $this->assertSame('local', $order->fresh()->shipping_type);
    }

    public function test_international_flow_full_walk_and_invariants(): void
    {
        $order = $this->checkoutOrder('international');

        foreach (['processing', 'packed', 'export_processing', 'shipped', 'in_transit', 'arrived_at_destination_country',
            'customs_clearance', 'customs_cleared', 'local_carrier', 'out_for_delivery'] as $step) {
            $this->change($order, $step);
            $this->assertInvariants($order);
        }

        // Phase 8 (D8-5): see local walk — normal delivered refused.
        try {
            $this->change($order, 'delivered');
            $this->fail('Normal delivered without the completion invariant must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('every fulfillment delivered', $e->getMessage());
        }

        $this->forceDeliver($order);

        $fresh = $order->fresh();
        $this->assertSame('delivered', $fresh->status);
        $this->assertSame('international', $fresh->shipping_type);
        $this->assertSame('international', $fresh->flow->code);
    }

    /**
     * Phase 8 (D8-5): audited force-delivered escape hatch for flow tests.
     * The admin carries update-order-status; the reason is recorded.
     */
    private function forceDeliver(Order $order): void
    {
        Sanctum::actingAs($this->admin);
        app(\App\Services\General\OrderService::class)->changeOrderStatus(
            null, 'delivered', $order->id, true, 'flow walk tail', null, true, [], false, false, true
        );
    }

    public function test_invalid_skips_and_backward_moves_fail(): void
    {
        $order = $this->checkoutOrder();
        $service = app(\App\Services\General\OrderService::class);

        foreach (['shipped', 'delivered', 'out_for_delivery'] as $skip) {
            try {
                $service->changeOrderStatus(null, $skip, $order->id);
                $this->fail("Skip to {$skip} must be rejected.");
            } catch (\RuntimeException) {
            }
        }

        $this->change($order, 'processing');

        foreach (['pending', 'delivered'] as $bad) {
            try {
                $service->changeOrderStatus(null, $bad, $order->id);
                $this->fail("Move to {$bad} must be rejected.");
            } catch (\RuntimeException) {
            }
        }

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_terminal_states_reject_everything_but_noop(): void
    {
        $order = $this->checkoutOrder();
        $service = app(\App\Services\General\OrderService::class);
        Sanctum::actingAs($this->admin);
        // completed is a milestone, not terminal. Phase 8 (D8-5): the
        // normal completed -> delivered tail requires the completion
        // invariant; the audited force path covers the remainder.
        $service->changeOrderStatus(null, 'completed', $order->id);
        try {
            $service->changeOrderStatus(null, 'delivered', $order->id);
            $this->fail('Normal delivered without the completion invariant must be refused.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('every fulfillment delivered', $e->getMessage());
        }
        $this->forceDeliver($order);
        $this->assertSame('delivered', $order->fresh()->status);

        foreach (['pending', 'processing', 'packed', 'completed', 'cancelled'] as $bad) {
            try {
                $service->changeOrderStatus(null, $bad, $order->id);
                $this->fail("Move from delivered to {$bad} must be rejected.");
            } catch (\RuntimeException) {
            }
        }

        // Same-status no-op stays allowed (legacy idempotency).
        $service->changeOrderStatus(null, 'delivered', $order->id);
        $this->assertSame('delivered', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Concurrency: stale reader loses to fresh writer
    // -----------------------------------------------------------------

    public function test_stale_state_concurrent_update_fails_safely(): void
    {
        $order = $this->checkoutOrder();
        $service = app(\App\Services\General\OrderService::class);

        // Two actors read `processing`.
        $this->change($order, 'processing');
        $stale = Order::query()->find($order->id);

        // Actor A advances processing -> packed (fresh lock, wins).
        $this->change($order, 'packed');

        // Actor B, holding the stale view, attempts processing -> shipped:
        // under lock the row is now packed, and packed -> shipped IS valid,
        // so use a truly invalid target to prove the guard reads fresh state.
        try {
            $service->changeOrderStatus(null, 'delivered', $stale->id);
            $this->fail('Stale concurrent skip must be rejected.');
        } catch (\RuntimeException) {
        }

        // And a repeat of the already-applied step is a harmless no-op
        // producing no duplicate history row.
        $historyCount = $stale->statusHistory()->count();
        $service->changeOrderStatus(null, 'packed', $stale->id);
        $this->assertSame($historyCount, $stale->statusHistory()->count());
        $this->assertSame('packed', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Seeder: idempotent + non-destructive + defaults + parity
    // -----------------------------------------------------------------

    public function test_seeder_is_idempotent_and_preserves_customizations(): void
    {
        $before = [
            OrderStatus::query()->count(),
            OrderFlow::query()->count(),
            \App\Models\OrderFlow\OrderFlowStatus::query()->count(),
        ];

        Artisan::call('db:seed', ['--class' => OrderFlowSeeder::class, '--force' => true]);
        Artisan::call('db:seed', ['--class' => OrderFlowSeeder::class, '--force' => true]);

        $this->assertSame($before[0], OrderStatus::query()->count());
        $this->assertSame($before[1], OrderFlow::query()->count());
        $this->assertSame($before[2], \App\Models\OrderFlow\OrderFlowStatus::query()->count());

        // Admin customization survives reseeding.
        $status = OrderStatus::query()->where('code', 'processing')->firstOrFail();
        $status->update(['name' => 'Admin Name', 'is_active' => false]);
        Artisan::call('db:seed', ['--class' => OrderFlowSeeder::class, '--force' => true]);
        $status->refresh();
        $this->assertSame('Admin Name', $status->name);
        $this->assertFalse((bool) $status->is_active);
    }

    public function test_exactly_one_active_default_per_shipping_type(): void
    {
        Artisan::call('db:seed', ['--class' => OrderFlowSeeder::class, '--force' => true]);

        $defaults = OrderFlow::query()->where('is_default', true)->where('is_active', true)->get();
        $this->assertSame(1, $defaults->where('shipping_type', 'local')->count());
        $this->assertSame(1, $defaults->where('shipping_type', 'international')->count());

        // Every active default flow resolves by its own shipping_type —
        // the value space is dynamic, so iterate the database, not a list.
        $service = app(OrderFlowService::class);
        foreach (OrderFlow::query()->where('is_default', true)->where('is_active', true)->pluck('shipping_type') as $type) {
            $flow = $service->resolveFlowForShippingType($type);
            $this->assertSame($type, $flow->shipping_type);
            $this->assertTrue($flow->statuses()->exists());
        }
    }

    public function test_catalog_and_enum_service_parity(): void
    {
        $catalogCodes = collect(OrderFlowService::catalogSeed())->pluck('code')->all();
        $flowCodes = collect(OrderFlowService::flowsSeed())
            ->flatMap(fn ($flow) => $flow['statuses'])->unique()->values()->all();

        // Every seeded code must be mirrorable (ENUM allow-list parity).
        foreach (array_merge($catalogCodes, $flowCodes) as $code) {
            $this->assertContains($code, OrderFlowService::ALL_STATUS_CODES, "Code {$code} missing from ALL_STATUS_CODES.");
        }

        // Every flow member must exist in the catalog seed.
        foreach ($flowCodes as $code) {
            $this->assertContains($code, $catalogCodes, "Flow code {$code} missing from catalog seed.");
        }

        // Custom-flows-only vocabulary is present but NOT in seeded flows
        // (export_processing graduated to the seeded international flow, §18).
        foreach (['confirmed', 'ready_to_ship'] as $code) {
            $this->assertContains($code, $catalogCodes);
            $this->assertNotContains($code, $flowCodes);
        }

        // §18: export_processing is catalogued AND seeded in international.
        $this->assertContains('export_processing', $catalogCodes);
        $this->assertContains('export_processing', $flowCodes);
    }
}
