<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
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
 * FINAL Order Flow architecture verification.
 *
 * Pins the authoritative decisions: controlled shipping_type identity,
 * pending-retry cross-type rejection, sanitized frontend contracts,
 * deactivation guardrails, multi_select options enforcement, permission
 * duality, and legacy-union transition parity.
 */
class FinalArchitectureTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const BATCH_URL = '/api/v1/orders/status';

    private const AVAILABLE_URL = '/api/v1/general/order-flows/available';

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
            'status' => true]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 0,
            'status' => true]);

        $this->user = User::create([
            'name' => 'Buyer',
            'email' => 'final-buyer@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'final-admin@example.com',
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
            'sold_quantity' => 0]);
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------

    private function baseCheckoutPayload(array $overrides = []): array
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

    private function checkout(User $user, array $payload)
    {
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/general/checkout', $payload);
    }

    private function freshUser(string $prefix = 'final'): User
    {
        return User::create([
            'name' => 'Buyer ' . Str::random(4),
            'email' => $prefix . '-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);
    }

    private function flowFor(string $shippingType): OrderFlow
    {
        return OrderFlow::query()->where('shipping_type', $shippingType)->firstOrFail();
    }

    private function statusId(string $code): int
    {
        return OrderStatus::query()->where('code', $code)->value('id');
    }

    private function makeOrder(string $shippingType = 'local', string $status = 'pending', ?User $owner = null): Order
    {
        $flow = $this->flowFor($shippingType);

        return Order::create([
            'user_id' => ($owner ?? $this->freshUser())->id,
            'name' => 'Final Buyer',
            'user_phone' => '01000000000',
            'user_email' => 'final-buyer@example.com',
            'address' => json_encode(['city' => 'Cairo']),
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => $status,
            'shipping_type' => $shippingType,
            'flow_id' => $flow->id,
            'current_status_id' => $this->statusId($status),
            'shipping_method' => 'SCHEDULED',
        ]);
    }

    private function batchAs(User $actor, array $payload)
    {
        Sanctum::actingAs($actor);

        return $this->patchJson(self::BATCH_URL, $payload);
    }

    private function adminPut(string $uri, array $payload)
    {
        Sanctum::actingAs($this->admin);

        return $this->putJson($uri, $payload);
    }

    // -----------------------------------------------------------------
    // A. Pending cross-type retry
    // -----------------------------------------------------------------

    public function test_pending_reuse_same_shipping_type(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'local']))->assertStatus(200);
        $first = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'local']))->assertStatus(200);

        $this->assertSame(1, Order::query()->where('user_id', $this->user->id)->count());
        $this->assertSame($first->flow_id, $first->fresh()->flow_id);
        $this->assertSame('local', $first->fresh()->shipping_type);
    }

    public function test_pending_reuse_defaults_to_local_when_omitted(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);

        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);

        $this->assertSame(1, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_pending_reuse_case_and_whitespace_variants_reuse(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'local']))->assertStatus(200);

        // Request normalization (trim/case) matches the stored type.
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => ' Local ']))->assertStatus(200);

        $this->assertSame(1, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_pending_reuse_different_shipping_type_rejected(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'local']))->assertStatus(200);

        $this->freshCartWithProduct($this->user);
        $resp = $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'international']));

        $resp->assertStatus(422);
        $this->assertStringContainsString(
            __('checkout.pending_order_shipping_type_conflict'),
            (string) $resp->getContent()
        );

        $order = Order::query()->where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('local', $order->shipping_type);
        $this->assertSame($this->flowFor('local')->id, (int) $order->flow_id);
    }

    public function test_pending_reuse_international_to_local_rejected(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'international']))->assertStatus(200);

        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'local']))->assertStatus(422);

        $order = Order::query()->where('user_id', $this->user->id)->firstOrFail();
        $this->assertSame('international', $order->shipping_type);
    }

    // -----------------------------------------------------------------
    // B. Flow resolution
    // -----------------------------------------------------------------

    public function test_resolution_rejects_unsupported_type(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'sea']))->assertStatus(422);
        $this->assertSame(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_resolution_fails_closed_without_active_flow(): void
    {
        $this->flowFor('international')->update(['is_active' => false]);

        $this->freshCartWithProduct($this->user);
        $resp = $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'international']));

        $resp->assertStatus(422);
        $this->assertSame(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_existing_order_continues_after_flow_deactivation(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        $this->flowFor('local')->update(['is_active' => false]);

        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'processing'])->assertOk();
        $this->assertSame('processing', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // C/D. is_active matrix + deactivation guardrails
    // -----------------------------------------------------------------

    public function test_cannot_deactivate_last_active_flow_for_type(): void
    {
        $flow = $this->flowFor('local');

        $resp = $this->adminPut("/api/v1/admin/order-flows/{$flow->id}", ['is_active' => false]);

        $resp->assertStatus(422);
        $this->assertStringContainsString(
            __('checkout.flow_deactivate_last_active'),
            (string) $resp->getContent()
        );
        $this->assertTrue($flow->fresh()->is_active);
    }

    public function test_deactivating_already_inactive_flow_is_noop(): void
    {
        $flow = $this->flowFor('local');
        $flow->update(['is_active' => false]);

        $this->adminPut("/api/v1/admin/order-flows/{$flow->id}", ['is_active' => false])->assertOk();
    }

    public function test_cannot_deactivate_status_holding_inflight_orders(): void
    {
        $this->makeOrder('local', 'pending', $this->user);
        $pendingId = $this->statusId('pending');

        $resp = $this->adminPut("/api/v1/admin/order-statuses/{$pendingId}", ['is_active' => false]);

        $resp->assertStatus(422);
        $this->assertTrue(OrderStatus::query()->find($pendingId)->is_active);
    }

    public function test_status_deactivation_allowed_when_only_terminal_holders(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'cancelled'])->assertOk();

        $pendingId = $this->statusId('pending');
        $this->adminPut("/api/v1/admin/order-statuses/{$pendingId}", ['is_active' => false])->assertOk();
        $this->assertFalse(OrderStatus::query()->find($pendingId)->fresh()->is_active);
    }

    public function test_inactive_status_cannot_be_entered_but_can_be_exited(): void
    {
        $order = $this->makeOrder('local', 'processing', $this->user);
        $packedId = $this->statusId('packed');
        OrderStatus::query()->whereKey($packedId)->update(['is_active' => false]);

        // processing -> packed must now fail (target inactive).
        $body = $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'packed'])->assertOk()->json();
        $this->assertSame(0, $body['data']['summary']['succeeded']);
        $this->assertSame('processing', $order->fresh()->status);

        // Supervised exit from an inactive current status still works.
        $order->update(['status' => 'packed', 'current_status_id' => $packedId]);
        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'shipped'])->assertOk();
    }

    public function test_retired_input_no_longer_demanded(): void
    {
        Sanctum::actingAs($this->admin);
        $flow = $this->flowFor('local');
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", ['inputs' => [[
            'key' => 'retire_me',
            'label' => ['en' => 'Retire me', 'ar' => 'تجاهلني'],
            'type' => 'text',
            'required' => true,
            'required_at' => 'checkout',
        ]]])->assertStatus(201);

        \App\Models\OrderFlow\FlowInput::query()
            ->where('flow_id', $flow->id)->where('key', 'retire_me')
            ->update(['is_active' => false]);

        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'local']))->assertStatus(200);
    }

    // -----------------------------------------------------------------
    // E. Membership reorder with in-flight orders stays coherent
    // -----------------------------------------------------------------

    public function test_reorder_with_inflight_orders_allowed_and_coherent(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        $flow = $this->flowFor('local');
        $byCode = $flow->statuses()->orderBy('order_flow_statuses.sort_order')->get()->keyBy('code');

        // Rotate: delivered becomes the immediate successor of pending.
        $ordered = ['pending', 'delivered', 'processing', 'packed', 'shipped', 'out_for_delivery'];
        $ids = collect($ordered)->map(fn ($code) => $byCode[$code]->id)->all();

        $this->adminPut("/api/v1/admin/order-flows/{$flow->id}", ['status_ids' => $ids])->assertOk();

        $next = app(\App\Services\OrderFlow\OrderFlowService::class)->nextStatus($flow->fresh(), 'pending');
        $this->assertNotNull($next);
        $this->assertSame('delivered', $next->code);

        $body = $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'delivered'])->assertOk()->json();
        $this->assertSame(1, $body['data']['summary']['succeeded']);
    }

    // -----------------------------------------------------------------
    // F. multi_select + options enforcement
    // -----------------------------------------------------------------

    public function test_multi_select_options_allow_list_enforced(): void
    {
        Sanctum::actingAs($this->admin);
        $flow = $this->flowFor('local');
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", ['inputs' => [[
            'key' => 'order_tags',
            'label' => ['en' => 'Tags', 'ar' => 'وسوم'],
            'type' => 'multi_select',
            'required' => true,
            'required_at' => 'checkout',
            'validation' => ['options' => ['vip', 'bulk']],
        ]]])->assertStatus(201);

        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload([
            'shipping_type' => 'local', 'flow_values' => ['order_tags' => ['vip']],
        ]))->assertStatus(200);
    }

    public function test_multi_select_options_rejects_unknown_option(): void
    {
        Sanctum::actingAs($this->admin);
        $flow = $this->flowFor('local');
        $this->postJson("/api/v1/admin/order-flows/{$flow->id}/inputs", ['inputs' => [[
            'key' => 'order_tags',
            'label' => ['en' => 'Tags', 'ar' => 'وسوم'],
            'type' => 'multi_select',
            'required' => true,
            'required_at' => 'checkout',
            'validation' => ['options' => ['vip', 'bulk']],
        ]]])->assertStatus(201);

        $this->freshCartWithProduct($this->user);
        $resp = $this->checkout($this->user, $this->baseCheckoutPayload([
            'shipping_type' => 'local', 'flow_values' => ['order_tags' => ['vip', 'nope']],
        ]));

        $resp->assertStatus(422);
        $this->assertSame(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    // -----------------------------------------------------------------
    // G. Available endpoint (guest-safe, sanitized)
    // -----------------------------------------------------------------

    public function test_available_endpoint_is_guest_accessible(): void
    {
        $this->getJson(self::AVAILABLE_URL)->assertStatus(200)->assertJsonPath('success', true);
    }

    public function test_available_returns_only_active_sanitized_flows(): void
    {
        Sanctum::actingAs($this->admin);
        $intl = $this->flowFor('international');
        $this->postJson("/api/v1/admin/order-flows/{$intl->id}/inputs", ['inputs' => [[
            'key' => 'from_country',
            'label' => ['en' => 'Origin country', 'ar' => 'بلد المنشأ'],
            'type' => 'select',
            'source' => 'countries',
            'required' => true,
            'required_at' => 'checkout',
        ]]])->assertStatus(201);

        // Deactivate international at the model level (the admin guard
        // would block last-active deactivation): it must vanish from
        // discovery while local stays.
        $intl->update(['is_active' => false]);

        $body = $this->getJson(self::AVAILABLE_URL)->assertOk()->json('data');
        $types = collect($body['flows'])->pluck('shipping_type')->all();
        $this->assertContains('local', $types);
        $this->assertNotContains('international', $types);

        $intl->update(['is_active' => true]);
        $body = $this->getJson(self::AVAILABLE_URL)->assertOk()->json('data');

        $this->assertArrayHasKey('flows', $body);
        $types = collect($body['flows'])->pluck('shipping_type')->all();
        $this->assertContains('local', $types);
        $this->assertContains('international', $types);

        $local = collect($body['flows'])->firstWhere('shipping_type', 'local');
        $this->assertSame(['shipping_type', 'code', 'name', 'statuses', 'inputs'], array_keys($local));
        $this->assertSame(['code', 'name', 'sort_order'], array_keys($local['statuses'][0]));
        $this->assertSame(['en' => 'Local Flow', 'ar' => 'المسار المحلي'], $local['name']);
        $this->assertSame(
            ['pending', 'processing', 'packed', 'shipped', 'out_for_delivery', 'delivered'],
            collect($local['statuses'])->pluck('code')->all()
        );
        $this->assertSame([1, 2, 3, 4, 5, 6], collect($local['statuses'])->pluck('sort_order')->all());

        $intlBody = collect($body['flows'])->firstWhere('shipping_type', 'international');
        $this->assertSame(
            ['key', 'label', 'placeholder', 'help_text', 'type', 'source', 'required', 'required_at', 'sort_order', 'validation'],
            array_keys($intlBody['inputs'][0])
        );
    }

    public function test_per_type_endpoint_uses_same_sanitized_contract(): void
    {
        Sanctum::actingAs($this->user);
        $body = $this->getJson('/api/v1/general/order-flows/by-shipping-type/international')->assertOk()->json('data');

        $this->assertSame('international', $body['shipping_type']);
        $this->assertArrayNotHasKey('id', $body);
        $this->assertArrayNotHasKey('is_active', $body);
        $this->assertArrayNotHasKey('is_default', $body);
        $this->assertArrayHasKey('statuses', $body);
        $this->assertArrayHasKey('inputs', $body);
    }

    // -----------------------------------------------------------------
    // H. Order resource contract (no admin metadata)
    // -----------------------------------------------------------------

    public function test_order_detail_exposes_no_internal_flow_metadata(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        Sanctum::actingAs($this->user);
        $body = $this->getJson("/api/v1/general/orders/{$order->id}")->assertOk()->json('data');

        $this->assertArrayNotHasKey('id', $body['flow']);
        $this->assertArrayNotHasKey('is_active', $body['flow']);
        $this->assertArrayNotHasKey('is_default', $body['flow']);
        $this->assertSame('local', $body['flow']['code']);
        $this->assertArrayNotHasKey('id', $body['current_status']);
        $this->assertSame('pending', $body['current_status']['code']);
    }

    public function test_order_list_stays_lightweight(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);

        Sanctum::actingAs($this->user);
        $item = $this->getJson('/api/v1/general/orders')->assertOk()->json('data.data.0');

        $this->assertSame('local', $item['flow']['code']);
        $this->assertNull($item['flow']['statuses']);
        $this->assertArrayNotHasKey('id', $item['flow']);
    }

    // -----------------------------------------------------------------
    // I. Permission duality
    // -----------------------------------------------------------------

    public function test_general_permission_without_target_is_denied_per_order(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        $staff = $this->freshUser('staff');
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-order-status', 'guard_name' => 'api']);
        $staff->givePermissionTo($perm);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $body = $this->batchAs($staff, ['order_ids' => [$order->id], 'status' => 'processing'])->json();

        $this->assertSame(0, $body['data']['summary']['succeeded']);
        $this->assertSame('missing_permission', $body['data']['results'][0]['error']['code']);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_target_permission_without_general_is_rejected(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        $staff = $this->freshUser('staff2');
        $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'change-order-status.processing', 'guard_name' => 'api']);
        $staff->givePermissionTo($perm);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        $this->batchAs($staff, ['order_ids' => [$order->id], 'status' => 'processing'])->assertStatus(403);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_both_permissions_allow_valid_transition(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);

        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'processing'])->assertOk();
        $this->assertSame('processing', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // J. Transition parity (legacy union locked in)
    // -----------------------------------------------------------------

    public function test_linear_skip_rejected(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);

        $body = $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'packed'])->json();

        $this->assertSame(0, $body['data']['summary']['succeeded']);
        $this->assertSame('pending', $order->fresh()->status);
    }

    public function test_supervised_completed_exit_allowed(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);

        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'completed'])->assertOk();
        $this->assertSame('completed', $order->fresh()->status);
    }

    public function test_completed_to_delivered_tail_preserved(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'completed'])->assertOk();

        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'delivered'])->assertOk();
        $this->assertSame('delivered', $order->fresh()->status);
    }

    public function test_cancelled_is_absorbing(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'cancelled'])->assertOk();

        $body = $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'processing'])->json();
        $this->assertSame(0, $body['data']['summary']['succeeded']);
    }

    public function test_returned_is_terminal(): void
    {
        $order = $this->makeOrder('local', 'pending', $this->user);
        foreach (['processing', 'packed', 'shipped', 'out_for_delivery', 'failed_delivery', 'returned'] as $step) {
            $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => $step])->assertOk();
        }
        $this->assertSame('returned', $order->fresh()->status);

        $body = $this->batchAs($this->admin, ['order_ids' => [$order->id], 'status' => 'processing'])->json();
        $this->assertSame(0, $body['data']['summary']['succeeded']);
        $this->assertSame('returned', $order->fresh()->status);
    }
}
