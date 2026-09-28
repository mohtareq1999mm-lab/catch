<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\FlowInput;
use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Schema\Blueprint;
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
 * Unified Order Status Mutation: PATCH /api/v1/orders/status.
 *
 * ONE endpoint for ONE order ([id]) or MANY ([ids]) through the same
 * orchestrator and the same business pipeline (OrderService, per order,
 * own transaction). Covers: single/multi/partial/all-failed, not-found,
 * validation, granular + payment permissions, mixed flows, flow inputs,
 * same-status no-op, mirror invariant, fast-order parity.
 */
class UnifiedOrderStatusTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const URL = '/api/v1/orders/status';

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
            'status' => true,
            'is_fast_shipping_enabled' => true]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 0,
            'status' => true]);

        $this->user = User::create([
            'name' => 'Buyer',
            'email' => 'buyer-batch@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-batch@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);
        if (Schema::hasTable('roles')) {
            $role = \Marvel\Database\Models\Role::firstOrCreate(
                ['name' => 'super_admin', 'guard_name' => 'api'],
                ['display_name' => ['en' => 'Super Admin', 'ar' => 'ادمن']]
            );
            $this->admin->assignRole($role);
        }
        if (Schema::hasTable('permissions')) {
            foreach (['update-order-status', 'payments.mark_paid', 'view-orders', 'view-order'] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
            // Production parity: general holders inherit granular targets.
            (new \Database\Seeders\OrderStatusPermissionSeeder)->run();
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->product = Product::create([
            'name' => 'Product A',
            'slug' => 'product-a-' . Str::random(6),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 20,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
            'is_fast_shipping_available' => true]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function flowFor(string $shippingType): OrderFlow
    {
        return OrderFlow::query()->where('shipping_type', $shippingType)->firstOrFail();
    }

    private function statusId(string $code): int
    {
        return OrderStatus::query()->where('code', $code)->value('id');
    }

    private function freshUser(string $prefix = 'batch'): User
    {
        return User::create([
            'name' => 'Buyer ' . Str::random(4),
            'email' => $prefix . '-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);
    }

    /**
     * Direct creation with correct flow linkage (isolates batch logic;
     * checkout paths are covered by their own suites). One pending order
     * per user (partial unique index) — each order gets its own user
     * unless an explicit owner is passed.
     */
    private function makeOrder(string $shippingType = 'local', string $status = 'pending', ?User $owner = null, array $overrides = []): Order
    {
        $flow = $this->flowFor($shippingType);

        return Order::create(array_merge([
            'user_id' => ($owner ?? $this->freshUser())->id,
            'name' => 'Batch Buyer',
            'user_phone' => '01000000000',
            'user_email' => 'buyer-batch@example.com',
            'address' => json_encode(['city' => 'Cairo']),
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => $status,
            'shipping_type' => $shippingType,
            'flow_id' => $flow->id,
            'current_status_id' => $this->statusId($status),
            'shipping_method' => 'SCHEDULED',
        ], $overrides));
    }

    private function batchAs(User $actor, array $payload)
    {
        Sanctum::actingAs($actor);

        return $this->patchJson(self::URL, $payload);
    }

    private function historyCount(Order $order): int
    {
        return $order->statusHistory()->reorder()->count();
    }

    // -----------------------------------------------------------------
    // Single / multi / partial / all-failed
    // -----------------------------------------------------------------

    public function test_single_order_success(): void
    {
        $order = $this->makeOrder();

        $resp = $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'processing'])
            ->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 1, 'succeeded' => 1, 'failed' => 0]);
        $resp->assertJsonPath('data.results.0.order_id', $order->id);
        $resp->assertJsonPath('data.results.0.success', true);
        $resp->assertJsonPath('data.results.0.status', 'processing');
        $resp->assertJsonPath('data.results.0.current_status.code', 'processing');
        $resp->assertJsonPath('data.results.0.current_status.sort_order', 2);

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame('processing', $order->currentStatus->code);
    }

    public function test_multiple_orders_all_success(): void
    {
        $a = $this->makeOrder();
        $b = $this->makeOrder();
        $c = $this->makeOrder();

        $resp = $this->batchAs($this->admin, ['order_ids' => [$a->id, $b->id, $c->id], 'status' => 'processing'])
            ->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 3, 'succeeded' => 3, 'failed' => 0]);

        foreach ([$a, $b, $c] as $order) {
            $this->assertSame('processing', $order->fresh()->status);
        }
    }

    public function test_partial_success_commits_winners_only(): void
    {
        $a = $this->makeOrder(); // pending -> processing: ok
        $b = $this->makeOrder('local', 'delivered'); // delivered -> processing: rejected
        $c = $this->makeOrder(); // pending -> processing: ok
        $bHistory = $this->historyCount($b);

        $resp = $this->batchAs($this->admin, ['order_ids' => [$a->id, $b->id, $c->id], 'status' => 'processing'])
            ->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 3, 'succeeded' => 2, 'failed' => 1]);

        $byId = collect($resp->json('data.results'))->keyBy('order_id')->all();
        $this->assertTrue($byId[$a->id]['success']);
        $this->assertFalse($byId[$b->id]['success']);
        $this->assertSame('forbidden_transition', $byId[$b->id]['error']['code']);
        $this->assertTrue($byId[$c->id]['success']);

        // Winners committed, loser byte-identical (status, mirror, history).
        $this->assertSame('processing', $a->fresh()->status);
        $this->assertSame('processing', $c->fresh()->status);
        $this->assertSame('delivered', $b->fresh()->status);
        $this->assertSame('delivered', $b->fresh()->currentStatus->code);
        $this->assertSame($bHistory, $this->historyCount($b->fresh()));
    }

    public function test_all_failed_leaves_everything_unchanged(): void
    {
        $a = $this->makeOrder('local', 'delivered');
        $b = $this->makeOrder('local', 'cancelled');

        $resp = $this->batchAs($this->admin, ['order_ids' => [$a->id, $b->id], 'status' => 'processing'])
            ->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 0, 'failed' => 2]);
        $this->assertFalse($resp->json('success'));

        $this->assertSame('delivered', $a->fresh()->status);
        $this->assertSame('cancelled', $b->fresh()->status);
    }

    public function test_missing_order_reported_per_order(): void
    {
        $a = $this->makeOrder();
        $ghost = 2147483647;

        $resp = $this->batchAs($this->admin, ['order_ids' => [$a->id, $ghost], 'status' => 'processing'])
            ->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 1, 'failed' => 1]);

        $byId = collect($resp->json('data.results'))->keyBy('order_id')->all();
        $this->assertTrue($byId[$a->id]['success']);
        $this->assertFalse($byId[$ghost]['success']);
        $this->assertSame('order_not_found', $byId[$ghost]['error']['code']);

        $this->assertSame('processing', $a->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Request validation
    // -----------------------------------------------------------------

    public function test_duplicate_ids_rejected(): void
    {
        $order = $this->makeOrder();

        $this->batchAs($this->admin, ['order_ids' => [$order->id, $order->id], 'status' => 'processing'])
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_empty_ids_rejected(): void
    {
        $this->batchAs($this->admin, ['order_ids' => [], 'status' => 'processing'])
            ->assertStatus(422);
    }

    public function test_invalid_status_rejected(): void
    {
        $order = $this->makeOrder();

        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'nope'])
            ->assertStatus(422);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_batch_size_capped(): void
    {
        $ids = range(1, \App\Services\General\OrderStatusBatchService::MAX_BATCH + 1);

        $this->batchAs($this->admin, ['order_ids' => $ids, 'status' => 'processing'])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Authorization
    // -----------------------------------------------------------------

    public function test_unauthenticated_rejected(): void
    {
        $order = $this->makeOrder();

        $this->patchJson(self::URL, ['order_ids' => [$order->id], 'status' => 'processing'])
            ->assertStatus(401);
    }

    public function test_general_permission_required(): void
    {
        $order = $this->makeOrder();

        $this->batchAs($this->user, ['order_ids' => [$order->id], 'status' => 'processing'])
            ->assertStatus(403);

        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_missing_granular_permission_per_order(): void
    {
        $order = $this->makeOrder();

        $op = User::create([
            'name' => 'Op', 'email' => 'op-batch-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'), 'type' => 'staff',
            'is_active' => true, 'email_verified_at' => now()]);
        // General gate only — deliberately no change-order-status.* grant.
        foreach (['update-order-status'] as $name) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
            $op->givePermissionTo($perm);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $resp = $this->batchAs($op, ['order_ids' => [$order->id], 'status' => 'processing'])
            ->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 1, 'succeeded' => 0, 'failed' => 1]);
        $resp->assertJsonPath('data.results.0.success', false);
        $resp->assertJsonPath('data.results.0.error.code', 'missing_permission');

        $this->assertSame('pending', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Mixed flows
    // -----------------------------------------------------------------

    public function test_mixed_flows_evaluated_independently(): void
    {
        $this->seedCustomsInput();
        $local = $this->makeOrder('local');
        // Walk the international order to arrived_at_destination_country.
        $intl = $this->makeOrder('international');
        Sanctum::actingAs($this->admin);
        foreach (['processing', 'packed', 'shipped', 'in_transit', 'arrived_at_destination_country'] as $step) {
            $this->patchJson(self::URL, ['order_ids' => [$intl->id], 'status' => $step])->assertOk();
        }

        $resp = $this->batchAs($this->admin, [
            'order_ids' => [$local->id, $intl->id],
            'status' => 'customs_clearance',
            'flow_values' => ['customs_reference' => 'CUS-2026-00125'],
        ])->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 1, 'failed' => 1]);

        $byId = collect($resp->json('data.results'))->keyBy('order_id')->all();
        $this->assertFalse($byId[$local->id]['success']);
        $this->assertSame('forbidden_transition', $byId[$local->id]['error']['code']);
        $this->assertTrue($byId[$intl->id]['success']);

        $this->assertSame('pending', $local->fresh()->status);
        $this->assertSame('customs_clearance', $intl->fresh()->status);
        $this->assertSame('CUS-2026-00125', $intl->fresh()->customs_reference);
    }

    // -----------------------------------------------------------------
    // Flow inputs
    // -----------------------------------------------------------------

    private function seedCustomsInput(): void
    {
        $flow = $this->flowFor('international');

        FlowInput::create([
            'flow_id' => $flow->id,
            'key' => 'customs_reference',
            'label' => ['en' => 'Customs reference', 'ar' => 'المرجع الجمركي'],
            'type' => FlowInput::TYPE_TEXT,
            'required' => true,
            'required_at' => 'transition:customs_clearance',
            'sort_order' => 1,
            'validation' => ['min' => 3, 'max' => 100],
            'is_active' => true,
        ]);
    }

    private function arrivedIntlOrder(): Order
    {
        $intl = $this->makeOrder('international');
        Sanctum::actingAs($this->admin);
        foreach (['processing', 'packed', 'shipped', 'in_transit', 'arrived_at_destination_country'] as $step) {
            $this->patchJson(self::URL, ['order_ids' => [$intl->id], 'status' => $step])->assertOk();
        }

        return $intl->fresh();
    }

    public function test_flow_input_gates_per_order(): void
    {
        $this->seedCustomsInput();
        $intl = $this->arrivedIntlOrder();

        // Missing.
        $resp = $this->batchAs($this->admin, ['order_ids' => [$intl->id], 'status' => 'customs_clearance'])
            ->assertOk();
        $this->assertSame('missing_flow_input', $resp->json('data.results.0.error.code'));

        // Unknown key (+ still missing required).
        $resp = $this->batchAs($this->admin, [
            'order_ids' => [$intl->id],
            'status' => 'customs_clearance',
            'flow_values' => ['nope' => 'x'],
        ])->assertOk();
        $this->assertSame('unknown_flow_input', $resp->json('data.results.0.error.code'));

        // Invalid (below min length).
        $resp = $this->batchAs($this->admin, [
            'order_ids' => [$intl->id],
            'status' => 'customs_clearance',
            'flow_values' => ['customs_reference' => 'ab'],
        ])->assertOk();
        $this->assertSame('invalid_flow_input', $resp->json('data.results.0.error.code'));

        $this->assertSame('arrived_at_destination_country', $intl->fresh()->status);

        // Valid.
        $resp = $this->batchAs($this->admin, [
            'order_ids' => [$intl->id],
            'status' => 'customs_clearance',
            'flow_values' => ['customs_reference' => 'CUS-2026-00125'],
        ])->assertOk();
        $this->assertTrue($resp->json('data.results.0.success'));
        $this->assertSame('customs_clearance', $intl->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Payment guard (F-1)
    // -----------------------------------------------------------------

    public function test_payment_completed_matrix(): void
    {
        // Paid order completes on the normal path.
        $paid = $this->makeOrder('local', 'processing', null, ['payment_status' => 'payment-success']);

        // Unpaid order without the permission is blocked with its own code.
        $unpaid = $this->makeOrder();

        $resp = $this->batchAs($this->admin, ['order_ids' => [$paid->id, $unpaid->id], 'status' => 'completed'])
            ->assertOk();

        // Admin holds payments.mark_paid (setUp) so the unpaid one succeeds
        // here; the blocked variant is proven below with a lean operator.
        $resp->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 2, 'failed' => 0]);
        $this->assertSame('completed', $paid->fresh()->status);
        $this->assertSame('completed', $unpaid->fresh()->status);
    }

    public function test_payment_permission_required_per_order(): void
    {
        $unpaid = $this->makeOrder('local', 'processing');

        $op = User::create([
            'name' => 'Op', 'email' => 'oppay-batch-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'), 'type' => 'staff',
            'is_active' => true, 'email_verified_at' => now()]);
        // General + granular completed, but deliberately NO payments.mark_paid.
        foreach (['update-order-status', OrderFlowService::targetStatusPermission('completed')] as $name) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
            $op->givePermissionTo($perm);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $resp = $this->batchAs($op, ['order_ids' => [$unpaid->id], 'status' => 'completed'])
            ->assertOk();

        $resp->assertJsonPath('data.results.0.success', false);
        $resp->assertJsonPath('data.results.0.error.code', 'payment_permission_required');
        $this->assertSame('processing', $unpaid->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Same-status, mirror, fast parity
    // -----------------------------------------------------------------

    public function test_mixed_payment_authority_in_one_batch(): void
    {
        $paid = $this->makeOrder('local', 'processing', null, ['payment_status' => 'payment-success']);
        $unpaid = $this->makeOrder('local', 'processing');

        $op = User::create([
            'name' => 'Op', 'email' => 'oppay2-batch-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'), 'type' => 'staff',
            'is_active' => true, 'email_verified_at' => now()]);
        foreach (['update-order-status', OrderFlowService::targetStatusPermission('completed')] as $name) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
            $op->givePermissionTo($perm);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $resp = $this->batchAs($op, ['order_ids' => [$paid->id, $unpaid->id], 'status' => 'completed'])
            ->assertOk();

        $resp->assertJsonPath('data.summary', ['total' => 2, 'succeeded' => 1, 'failed' => 1]);

        $byId = collect($resp->json('data.results'))->keyBy('order_id')->all();
        $this->assertTrue($byId[$paid->id]['success']);
        $this->assertFalse($byId[$unpaid->id]['success']);
        $this->assertSame('payment_permission_required', $byId[$unpaid->id]['error']['code']);

        $this->assertSame('completed', $paid->fresh()->status);
        $this->assertSame('processing', $unpaid->fresh()->status);
    }

    public function test_retry_after_partial_success_is_safe(): void
    {
        $a = $this->makeOrder();
        $b = $this->makeOrder('local', 'delivered');

        $payload = ['order_ids' => [$a->id, $b->id], 'status' => 'processing'];

        $first = $this->batchAs($this->admin, $payload)->assertOk();
        $this->assertSame(['total' => 2, 'succeeded' => 1, 'failed' => 1], $first->json('data.summary'));

        // Retry the identical batch: the winner is now a same-status
        // no-op success, the loser fails again — never a skip-ahead.
        $second = $this->batchAs($this->admin, $payload)->assertOk();
        $byId = collect($second->json('data.results'))->keyBy('order_id')->all();
        $this->assertTrue($byId[$a->id]['success']);
        $this->assertFalse($byId[$b->id]['success']);

        $this->assertSame('processing', $a->fresh()->status);
        $this->assertSame('delivered', $b->fresh()->status);
    }

    public function test_same_status_is_noop_success(): void
    {
        $order = $this->makeOrder();
        $history = $this->historyCount($order);

        $resp = $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'pending'])
            ->assertOk();

        $this->assertTrue($resp->json('data.results.0.success'));
        $this->assertSame('pending', $order->fresh()->status);
        $this->assertSame($history, $this->historyCount($order->fresh()));
    }

    public function test_mirror_equality_after_batch(): void
    {
        $a = $this->makeOrder();
        $b = $this->makeOrder();

        $this->batchAs($this->admin, ['order_ids' => [$a->id, $b->id], 'status' => 'processing'])
            ->assertOk();

        foreach ([$a, $b] as $order) {
            $fresh = $order->fresh();
            $this->assertSame($fresh->status, $fresh->currentStatus->code);

            $sort = (int) \Illuminate\Support\Facades\DB::table('order_flow_statuses')
                ->where('flow_id', $fresh->flow_id)
                ->where('status_id', $fresh->current_status_id)
                ->value('sort_order');
            $this->assertSame(2, $sort);
        }
    }

    public function test_fast_order_batches_as_local(): void
    {
        // Fast checkout fixture (fast product + enabled governorate).
        $settings = Settings::query()->first();
        $options = $settings->options ?? [];
        $options['fast_shipping'] = [
            'enabled' => true, 'duration_minutes' => 120, 'fee' => 0,
            'start_hour' => '00:00', 'end_hour' => '23:59',
        ];
        $settings->update(['options' => $options]);
        \Illuminate\Support\Facades\Cache::forget('fast_shipping_settings');

        $cart = Cart::firstOrCreate(
            ['user_id' => $this->user->id, 'status' => 'active'],
            ['total_price' => 0, 'coupon' => null]
        );
        $cart->items()->delete();
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => $this->product->price,
            'total_price' => $this->product->price,
            'shipping_method' => ShippingMethod::FAST,
            'is_gift' => false]);
        $cart->update(['total_price' => $this->product->price]);

        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
        ])->assertStatus(200);
        $fast = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        $resp = $this->batchAs($this->admin, ['order_ids' => [$fast->id], 'status' => 'processing'])
            ->assertOk();

        $this->assertTrue($resp->json('data.results.0.success'));
        $this->assertSame('local', $fast->fresh()->shipping_type);
        $this->assertSame('processing', $fast->fresh()->status);
    }
}
