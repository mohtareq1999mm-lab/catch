<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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
use Marvel\Enums\OrderStatus as LegacyOrderStatus;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Order lifecycle responses: Fast Shipping parity, mirror invariant,
 * enriched flow payloads (details vs lists), and query-count safety.
 */
class OrderLifecycleResponseTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

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
        // Fast Shipping available around the clock in tests.
        $settings = Settings::query()->first();
        $options = $settings->options ?? [];
        $options['fast_shipping'] = [
            'enabled' => true, 'duration_minutes' => 120, 'fee' => 0,
            'start_hour' => '00:00', 'end_hour' => '23:59',
        ];
        $settings->update(['options' => $options]);
        Cache::forget('fast_shipping_settings');

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
            'email' => 'buyer-lifecycle@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-lifecycle@example.com',
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
            foreach ([
                'update-order-status', 'payments.mark_paid', 'view-orders', 'view-order',
                'view-order-flows', 'create-order-flows', 'update-order-flows', 'manage-order-flow-inputs',
            ] as $name) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
                $this->admin->givePermissionTo($perm);
            }
            foreach (\App\Services\OrderFlow\OrderFlowService::ALL_STATUS_CODES as $code) {
                $perm = \Spatie\Permission\Models\Permission::firstOrCreate(
                    ['name' => \App\Services\OrderFlow\OrderFlowService::targetStatusPermission($code), 'guard_name' => 'api']);
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

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => $this->user->email,
            'address' => ['street' => '123 Main St'],
            'governorate_id' => $this->governorate->id,
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery'], $overrides);
    }

    private function freshCart(string $shippingMethod = ShippingMethod::SCHEDULED, ?User $user = null): void
    {
        $user ??= $this->user;
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
            'shipping_method' => $shippingMethod,
            'is_gift' => false]);
        $cart->update(['total_price' => $this->product->price]);
    }

    // -----------------------------------------------------------------
    // Fast Shipping parity
    // -----------------------------------------------------------------

    public function test_fast_checkout_creates_local_flow_order(): void
    {
        $this->freshCart(ShippingMethod::FAST);
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload())
            ->assertStatus(200);

        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $this->assertSame('local', $order->shipping_type);
        $this->assertSame('FAST', $order->shipping_method);
        $this->assertSame('pending', $order->status);
        $this->assertNotNull($order->flow_id);
        $this->assertSame('local', $order->flow->code);
        $this->assertSame('pending', $order->currentStatus->code);

        // Same transitions as a normal local order.
        Sanctum::actingAs($this->admin);
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'processing'])
            ->assertStatus(200);
        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_fast_checkout_rejects_non_local_shipping_type(): void
    {
        foreach (['international', 'hyperloop'] as $type) {
            $this->freshCart(ShippingMethod::FAST);
            Sanctum::actingAs($this->user);

            $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload([
                'shipping_type' => $type,
            ]))->assertStatus(422);
        }

        $this->assertSame(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    // -----------------------------------------------------------------
    // Fast Shipping pending-reuse isolation (TEST 9)
    // -----------------------------------------------------------------

    public function test_fast_checkout_never_adopts_international_pending_order(): void
    {
        // Existing INTERNATIONAL pending order (normal checkout).
        $this->freshCart(ShippingMethod::SCHEDULED);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->basePayload([
            'shipping_type' => 'international',
        ]))->assertStatus(200);
        $intl = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $this->assertSame('international', $intl->shipping_type);

        // Fast Shipping retry must NOT adopt it as a local fast order.
        $this->freshCart(ShippingMethod::FAST);
        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload())
            ->assertStatus(422);

        $this->assertSame(1, Order::query()->where('user_id', $this->user->id)->count());
        $intl->refresh();
        $this->assertSame('international', $intl->shipping_type);
        $this->assertSame('pending', $intl->status);
    }

    public function test_fast_checkout_reuses_local_pending_order(): void
    {
        // Existing LOCAL pending order: same-flow retry supersedes the
        // committed pending (cancel + fresh) instead of reusing it.
        $this->freshCart(ShippingMethod::FAST);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload())
            ->assertStatus(200);
        $first = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        $this->freshCart(ShippingMethod::FAST);
        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload())
            ->assertStatus(200);

        $orders = Order::query()->where('user_id', $this->user->id)->orderBy('id')->get();
        $this->assertSame(2, $orders->count());
        $this->assertSame('cancelled', $orders[0]->status);
        $this->assertSame('pending', $orders[1]->status);
        $this->assertSame($first->flow_id, $orders[1]->flow_id, 'Replacement keeps the local flow');
    }

    // -----------------------------------------------------------------
    // Flow Input parity on the Local Flow (TEST 8)
    // -----------------------------------------------------------------

    public function test_local_checkout_input_gates_apply_identically_to_fast(): void
    {
        $flow = \App\Models\OrderFlow\OrderFlow::query()->where('shipping_type', 'local')->firstOrFail();
        \App\Models\OrderFlow\FlowInput::create([
            'flow_id' => $flow->id,
            'key' => 'delivery_note',
            'label' => ['en' => 'Delivery note', 'ar' => 'ملاحظة التسليم'],
            'type' => \App\Models\OrderFlow\FlowInput::TYPE_TEXT,
            'required' => true,
            'required_at' => \App\Models\OrderFlow\FlowInput::REQUIRED_AT_CHECKOUT,
            'sort_order' => 1,
            'is_active' => true,
        ]);

        // Missing input: both paths 422, no order.
        $this->freshCart(ShippingMethod::SCHEDULED);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->basePayload())->assertStatus(422);

        $this->freshCart(ShippingMethod::FAST);
        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload())->assertStatus(422);

        // Unknown input: both paths 422, no order.
        $this->freshCart(ShippingMethod::SCHEDULED);
        $this->postJson('/api/v1/general/checkout', $this->basePayload([
            'flow_values' => ['bogus_key' => 'x'],
        ]))->assertStatus(422);

        $this->freshCart(ShippingMethod::FAST);
        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload([
            'flow_values' => ['bogus_key' => 'x'],
        ]))->assertStatus(422);

        $this->assertSame(0, Order::query()->count());

        // Valid input: both paths create local pending orders.
        $this->freshCart(ShippingMethod::SCHEDULED);
        $this->postJson('/api/v1/general/checkout', $this->basePayload([
            'flow_values' => ['delivery_note' => 'Leave at door'],
        ]))->assertStatus(200);

        $user2 = User::create([
            'name' => 'Buyer 2', 'email' => 'buyer2-lifecycle@example.com',
            'password' => bcrypt('password'), 'type' => 'user',
            'is_active' => true, 'email_verified_at' => now()]);
        $this->freshCart(ShippingMethod::FAST, $user2);
        Sanctum::actingAs($user2);
        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload([
            'user_email' => $user2->email,
            'flow_values' => ['delivery_note' => 'Ring twice'],
        ]))->assertStatus(200);

        $this->assertSame(2, Order::query()->count());
        $this->assertSame(
            ['local', 'local'],
            Order::query()->orderBy('id')->pluck('shipping_type')->all()
        );
    }

    // -----------------------------------------------------------------
    // Fast order full lifecycle walk (TEST 32)
    // -----------------------------------------------------------------

    public function test_fast_order_walks_the_same_local_flow(): void
    {
        $this->freshCart(ShippingMethod::FAST);
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/fast-shipping/checkout', $this->basePayload())
            ->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        Sanctum::actingAs($this->admin);
        $expectedSort = 1;
        foreach (['processing', 'packed', 'shipped', 'out_for_delivery'] as $code) {
            $expectedSort++;
            $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => $code])
                ->assertOk()
                ->assertJsonPath('data.current_status.code', $code)
                ->assertJsonPath('data.current_status.sort_order', $expectedSort);
            $order->refresh();
            $this->assertSame($code, $order->status);
            $this->assertSame($code, $order->currentStatus->code);
        }

        // Phase 8 (D8-5): the normal delivered step requires the shipment
        // completion invariant — refused (legacy 422 transition contract).
        $this->patchJson("/api/v1/orders/{$order->id}/status", ['status' => 'delivered'])
            ->assertStatus(422);
        $this->assertSame('out_for_delivery', $order->fresh()->status);

        $this->assertSame('local', $order->fresh()->shipping_type);
    }

    // -----------------------------------------------------------------
    // Mirror invariant on the legacy path
    // -----------------------------------------------------------------

    public function test_legacy_path_keeps_status_mirror_in_sync(): void
    {
        $this->freshCart();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->basePayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        $request = \Illuminate\Http\Request::create(
            "/api/v1/orders/{$order->id}", 'PUT',
            ['id' => $order->id, 'order_status' => LegacyOrderStatus::PROCESSING]
        );
        $request->setUserResolver(fn () => $this->admin);

        app(\Marvel\Database\Repositories\OrderRepository::class)->updateOrder($request);

        $order->refresh();
        $this->assertSame('processing', $order->status);
        $this->assertSame('processing', $order->currentStatus->code);
    }

    // -----------------------------------------------------------------
    // Response enrichment
    // -----------------------------------------------------------------

    public function test_customer_detail_exposes_flow_stages_and_position(): void
    {
        $this->freshCart();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->basePayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        $body = $this->getJson("/api/v1/general/orders/{$order->id}")->assertOk()->json('data');

        $this->assertSame('local', $body['shipping_type']);
        $this->assertSame('local', $body['flow']['code']);
        $this->assertSame('المسار المحلي', $body['flow']['name']['ar']);
        $codes = collect($body['flow']['statuses'])->pluck('code')->all();
        $this->assertSame(['pending', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered'], $codes);
        $sorts = collect($body['flow']['statuses'])->pluck('sort_order')->all();
        $this->assertSame([1, 2, 3, 4, 5, 6], $sorts);
        $this->assertSame('pending', $body['current_status']['code']);
        $this->assertSame(1, $body['current_status']['sort_order']);
        // Legacy mirror retained alongside.
        $this->assertSame('pending', $body['status']);
    }

    public function test_customer_list_omits_stages_but_keeps_position(): void
    {
        $this->freshCart();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->basePayload())->assertStatus(200);

        $item = $this->getJson('/api/v1/general/orders')->assertOk()->json('data.data.0');
        $this->assertSame('local', $item['flow']['code']);
        $this->assertNull($item['flow']['statuses']);
        $this->assertSame('pending', $item['current_status']['code']);
    }

    public function test_admin_show_exposes_stages(): void
    {
        $this->freshCart();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->basePayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        Sanctum::actingAs($this->admin);
        $body = $this->getJson("/api/v1/orders/{$order->id}")->assertOk()->json('data');
        $this->assertNotEmpty($body['flow']['statuses']);
        $this->assertSame(1, $body['current_status']['sort_order']);
    }

    public function test_detail_query_count_stays_flat(): void
    {
        $this->freshCart();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->basePayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        DB::enableQueryLog();
        $this->getJson("/api/v1/general/orders/{$order->id}")->assertOk();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Eager-loaded detail must not fan out (generous bound; CI tunes).
        $this->assertLessThan(60, $count, "Detail executed {$count} queries; check for N+1.");
    }
}
