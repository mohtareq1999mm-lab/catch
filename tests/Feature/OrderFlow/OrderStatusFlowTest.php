<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

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
use Marvel\Database\Models\PickupLocation;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Order Status catalog + Shipping Flow selection + linear transitions.
 *
 * Covers: catalog list/update/code-stability/activation, flow create/reorder/
 * validation/default-rules, checkout default-local/international/invalid/
 * unavailable, order assignment/first-status/valid-next/invalid-skip/sync/
 * history, and legacy payment-milestone preservation.
 */
class OrderStatusFlowTest extends TestCase
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

        // History table (production: 2026_09_11 migration). Absent from the
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
                $table->index(['order_id', 'changed_at']);
                $table->index('changed_by');
                $table->index('new_status');
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

    private function checkout(User $user, array $payload)
    {
        Sanctum::actingAs($user);

        return $this->postJson(self::CHECKOUT_PREFIX . '/checkout', $payload);
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

    private function adminGet(string $uri)
    {
        Sanctum::actingAs($this->admin);

        return $this->getJson($uri);
    }

    private function adminPut(string $uri, array $payload)
    {
        Sanctum::actingAs($this->admin);

        return $this->putJson($uri, $payload);
    }

    private function adminPost(string $uri, array $payload)
    {
        Sanctum::actingAs($this->admin);

        return $this->postJson($uri, $payload);
    }

    private function statusId(string $code): int
    {
        return OrderStatus::query()->where('code', $code)->value('id');
    }

    // -----------------------------------------------------------------
    // Catalog
    // -----------------------------------------------------------------

    public function test_catalog_lists_seeded_statuses(): void
    {
        $resp = $this->adminGet('/api/v1/admin/order-statuses');

        $resp->assertOk();
        $codes = collect($resp->json('data.data'))->pluck('code')->all();
        foreach (['pending', 'processing', 'packed', 'shipped', 'customs_clearance', 'delivered', 'cancelled'] as $code) {
            $this->assertContains($code, $codes);
        }
    }

    public function test_catalog_update_changes_name_not_code(): void
    {
        $id = $this->statusId('processing');

        $resp = $this->adminPut("/api/v1/admin/order-statuses/{$id}", ['name' => 'Preparing Order']);

        $resp->assertOk();
        $this->assertSame('Preparing Order', $resp->json('data.name'));
        $this->assertSame('processing', $resp->json('data.code'));
        $this->assertSame('processing', OrderStatus::query()->find($id)->code);
    }

    public function test_catalog_code_is_unique(): void
    {
        $this->expectException(\Illuminate\Database\QueryException::class);

        OrderStatus::create(['code' => 'pending', 'name' => 'Dupe', 'is_active' => true]);
    }

    public function test_inactive_status_cannot_join_flow(): void
    {
        $flow = OrderFlow::query()->where('shipping_type', 'local')->firstOrFail();
        $customsId = $this->statusId('customs_clearance');
        OrderStatus::query()->where('id', $customsId)->update(['is_active' => false]);

        $ids = $flow->statuses()->orderBy('order_flow_statuses.sort_order')->pluck('order_statuses.id')->all();
        $ids[] = $customsId;

        $resp = $this->adminPut("/api/v1/admin/order-flows/{$flow->id}", ['status_ids' => $ids]);

        $resp->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Flows
    // -----------------------------------------------------------------

    public function test_flow_show_returns_ordered_statuses(): void
    {
        $flow = OrderFlow::query()->where('shipping_type', 'international')->firstOrFail();

        $resp = $this->adminGet("/api/v1/admin/order-flows/{$flow->id}");

        $resp->assertOk();
        $codes = collect($resp->json('data.statuses'))->pluck('code')->all();
        $this->assertSame(
            ['pending', 'processing', 'packed', 'shipped', 'in_transit', 'arrived_at_destination_country',
                'customs_clearance', 'customs_cleared', 'local_carrier', 'out_for_delivery', 'delivered'],
            $codes
        );
    }

    public function test_flow_reorder_relinks_neighbours(): void
    {
        $flow = OrderFlow::query()->where('shipping_type', 'international')->firstOrFail();
        $service = app(OrderFlowService::class);

        // Remove arrived_at_destination_country: in_transit must now lead
        // directly to customs_clearance (neighbours re-link by order).
        $ids = $flow->statuses()->orderBy('order_flow_statuses.sort_order')->pluck('order_statuses.id')->all();
        $ids = array_values(array_filter($ids, fn ($id) => $id !== $this->statusId('arrived_at_destination_country')));

        $this->adminPut("/api/v1/admin/order-flows/{$flow->id}", ['status_ids' => $ids])->assertOk();

        $next = $service->nextStatus($flow->fresh(), 'in_transit');
        $this->assertNotNull($next);
        $this->assertSame('customs_clearance', $next->code);
    }

    public function test_flow_rejects_duplicate_statuses(): void
    {
        $flow = OrderFlow::query()->where('shipping_type', 'local')->firstOrFail();
        $ids = $flow->statuses()->orderBy('order_flow_statuses.sort_order')->pluck('order_statuses.id')->all();
        $ids[] = $ids[0];

        $this->adminPut("/api/v1/admin/order-flows/{$flow->id}", ['status_ids' => $ids])->assertStatus(422);
    }

    public function test_second_flow_for_same_shipping_type_rejected(): void
    {
        $resp = $this->adminPost('/api/v1/admin/order-flows', [
            'code' => 'local-two',
            'name' => 'Local Two',
            'shipping_type' => 'local',
            'status_ids' => [$this->statusId('pending'), $this->statusId('delivered')],
        ]);

        $resp->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Checkout selection
    // -----------------------------------------------------------------

    public function test_checkout_defaults_to_local_flow(): void
    {
        $this->freshCartWithProduct($this->user);

        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);

        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $this->assertSame('local', $order->shipping_type);
        $this->assertSame('local', $order->flow->code);
        $this->assertSame('pending', $order->status);
        $this->assertSame('pending', $order->currentStatus->code);
    }

    public function test_checkout_with_international_selects_international_flow(): void
    {
        $this->freshCartWithProduct($this->user);

        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'international']))->assertStatus(200);

        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $this->assertSame('international', $order->shipping_type);
        $this->assertSame('international', $order->flow->code);
        $this->assertSame('pending', $order->status);
    }

    public function test_checkout_with_unsupported_shipping_type_fails(): void
    {
        $this->freshCartWithProduct($this->user);

        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'teleport']))
            ->assertStatus(422);

        $this->assertSame(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_checkout_with_unavailable_international_fails_closed(): void
    {
        OrderFlow::query()->where('shipping_type', 'international')->update(['is_active' => false]);
        $this->freshCartWithProduct($this->user);

        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'international']))
            ->assertStatus(422);

        $this->assertSame(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_payment_retry_keeps_assigned_flow(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload(['shipping_type' => 'international']))->assertStatus(200);
        $first = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        // Retry reuses the pending order; the flow must remain stable even if
        // the retry omits (or changes) the shipping type.
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);
        $second = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        $this->assertSame($first->id, $second->id);
        $this->assertSame('international', $second->fresh()->shipping_type);
        $this->assertSame($first->flow_id, $second->fresh()->flow_id);
    }

    // -----------------------------------------------------------------
    // Transitions
    // -----------------------------------------------------------------

    public function test_valid_next_transition_syncs_mirror_and_history(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $service = app(\App\Services\General\OrderService::class);

        $service->changeOrderStatus(null, 'processing', $order->id);
        $service->changeOrderStatus(null, 'packed', $order->id);

        $order->refresh();
        $this->assertSame('packed', $order->status);
        $this->assertSame('packed', $order->currentStatus->code);

        $history = $order->statusHistory()->orderBy('id')->get();
        $this->assertTrue($history->count() >= 3);
        $last = $history->last();
        $this->assertSame('processing', $last->old_status);
        $this->assertSame('packed', $last->new_status);
        $this->assertSame('local', $last->metadata['flow_code'] ?? null);
        $this->assertSame('packed', $last->metadata['to_code'] ?? null);
    }

    public function test_skipped_status_is_rejected(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $service = app(\App\Services\General\OrderService::class);

        $service->changeOrderStatus(null, 'processing', $order->id);

        try {
            $service->changeOrderStatus(null, 'shipped', $order->id);
            $this->fail('Skipped status must be rejected.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('processing', $e->getMessage());
        }

        $this->assertSame('processing', $order->fresh()->status);
    }

    public function test_legacy_payment_milestone_preserved(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();

        Sanctum::actingAs($this->admin);
        app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        $order->refresh();
        $this->assertSame('completed', $order->status);
        $this->assertSame('completed', $order->currentStatus->code);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $order->payment_status);
    }

    public function test_cancellation_exits_flow_from_logistics_step(): void
    {
        $this->freshCartWithProduct($this->user);
        $this->checkout($this->user, $this->baseCheckoutPayload())->assertStatus(200);
        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $service = app(\App\Services\General\OrderService::class);

        $service->changeOrderStatus(null, 'processing', $order->id);
        $service->changeOrderStatus(null, 'packed', $order->id);
        $service->changeOrderStatus(null, 'cancelled', $order->id);

        $order->refresh();
        $this->assertSame('cancelled', $order->status);
        $this->assertSame('cancelled', $order->currentStatus->code);
    }
}
