<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use Illuminate\Foundation\Testing\DatabaseTransactions;
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
 * Customer lifecycle contract: self-cancellation, order state visibility,
 * and admin shipment authorization (SEC-1).
 */
class CustomerLifecycleContractTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $user;

    private User $other;

    private User $admin;

    private Product $product;

    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Queue::fake();

        $this->createAllTestTables();

        // Shipment tracking columns (production: 2026_09_21 migration).
        // Absent from the shared trait, so the shipment listener's column
        // writes would fail without them.
        foreach ([
            'shipment_status' => 'string',
            'tracking_number' => 'string',
            'courier_name' => 'string',
        ] as $column => $type) {
            if (!Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function ($table) use ($column) {
                    $table->string($column, 100)->nullable();
                });
            }
        }
        foreach (['estimated_delivery_at', 'actual_delivery_at'] as $column) {
            if (!Schema::hasColumn('orders', $column)) {
                Schema::table('orders', function ($table) use ($column) {
                    $table->timestamp($column)->nullable();
                });
            }
        }

        // Shipment timeline table (production: order_tracking_events
        // migration). Absent from the shared trait; the shipment listener
        // writes the timeline row BEFORE mutating the order, so without
        // this table the order mutation is silently skipped.
        if (!Schema::hasTable('order_tracking_events')) {
            Schema::create('order_tracking_events', function ($table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->string('event_type');
                $table->timestamp('event_timestamp')->nullable();
                $table->string('actor_type')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('actor_name')->nullable();
                $table->string('old_status')->nullable();
                $table->string('new_status')->nullable();
                $table->json('metadata')->nullable();
                $table->boolean('customer_visible')->default(false);
                $table->string('customer_label_key')->nullable();
                $table->string('customer_description_key')->nullable();
                $table->text('admin_notes')->nullable();
                $table->string('source')->nullable();
                $table->string('ip_address')->nullable();
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
            'email' => 'lifecycle-buyer@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->other = User::create([
            'name' => 'Other',
            'email' => 'lifecycle-other@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'lifecycle-admin@example.com',
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now()]);
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

    private function checkoutAs(User $user, array $overrides = [])
    {
        $this->freshCartWithProduct($user);
        Sanctum::actingAs($user);

        return $this->postJson('/api/v1/general/checkout', $this->baseCheckoutPayload($overrides));
    }

    private function latestOrderFor(User $user): Order
    {
        return Order::query()->where('user_id', $user->id)->latest('id')->firstOrFail();
    }

    private function makeOrderFor(User $owner, string $status = 'pending'): Order
    {
        $flow = \App\Models\OrderFlow\OrderFlow::query()->where('shipping_type', 'local')->firstOrFail();
        $statusId = \App\Models\OrderFlow\OrderStatus::query()->where('code', $status)->value('id');

        return Order::create([
            'user_id' => $owner->id,
            'name' => 'Lifecycle Buyer',
            'user_phone' => '01000000000',
            'user_email' => 'lifecycle-buyer@example.com',
            'address' => json_encode(['city' => 'Cairo']),
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => $status,
            'shipping_type' => 'local',
            'flow_id' => $flow->id,
            'current_status_id' => $statusId,
            'shipping_method' => 'SCHEDULED',
        ]);
    }

    // -----------------------------------------------------------------
    // Customer self-cancellation
    // -----------------------------------------------------------------

    public function test_owner_can_cancel_pending_order(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->user);
        $body = $this->postJson("/api/v1/general/orders/{$order->id}/cancel")->assertOk()->json('data');

        $this->assertSame('cancelled', $body['status']);
        $this->assertSame('cancelled', $this->latestOrderFor($this->user)->status);
        // Inventory reservation released by the canonical pipeline.
        $this->assertSame(Order::INVENTORY_STATE_RELEASED, $this->latestOrderFor($this->user)->inventory_state);
    }

    public function test_owner_can_cancel_processing_order(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/status', ['order_ids' => [$order->id], 'status' => 'processing'])->assertOk();

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/general/orders/{$order->id}/cancel")->assertOk();
        $this->assertSame('cancelled', $this->latestOrderFor($this->user)->status);
    }

    public function test_cancel_paid_order_rejected(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->admin);
        $this->patchJson('/api/v1/orders/status', ['order_ids' => [$order->id], 'status' => 'completed'])->assertOk();

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/general/orders/{$order->id}/cancel")->assertStatus(422);
        $this->assertSame('completed', $this->latestOrderFor($this->user)->status);
    }

    public function test_cancel_foreign_order_not_found(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->other);
        $this->postJson("/api/v1/general/orders/{$order->id}/cancel")->assertStatus(404);
        $this->assertSame('pending', $this->latestOrderFor($this->user)->status);
    }

    public function test_double_cancel_rejected(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/general/orders/{$order->id}/cancel")->assertOk();
        $this->postJson("/api/v1/general/orders/{$order->id}/cancel")->assertStatus(422);
    }

    public function test_cancel_requires_authentication(): void
    {
        // No Sanctum actor at all: direct-created order, bare request.
        $order = $this->makeOrderFor($this->user);

        $this->postJson("/api/v1/general/orders/{$order->id}/cancel")->assertStatus(401);
        $this->assertSame('pending', $order->fresh()->status);
    }

    // -----------------------------------------------------------------
    // Order state visibility (CON-1)
    // -----------------------------------------------------------------

    public function test_order_payload_exposes_payment_and_fulfillment_state(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->user);
        $body = $this->getJson("/api/v1/general/orders/{$order->id}")->assertOk()->json('data');

        $this->assertArrayHasKey('payment_status', $body);
        $this->assertArrayHasKey('fulfillment_status', $body);
        $this->assertSame(Order::PAYMENT_STATUS_PENDING, $body['payment_status']);
    }

    // -----------------------------------------------------------------
    // Admin shipment authorization (SEC-1)
    // -----------------------------------------------------------------

    public function test_customer_cannot_read_admin_shipment(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->user);
        $this->getJson("/api/v1/admin/orders/{$order->id}/shipment")->assertStatus(403);
    }

    public function test_customer_cannot_mutate_admin_shipment(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        Sanctum::actingAs($this->user);
        $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/update-status", ['status' => 'delivered'])
            ->assertStatus(403);
        $this->assertNotSame('delivered', $this->latestOrderFor($this->user)->shipment_status);
    }

    public function test_admin_with_shipment_permission_can_update(): void
    {
        $this->checkoutAs($this->user)->assertStatus(200);
        $order = $this->latestOrderFor($this->user);

        foreach (['view-shipment', 'update-shipment'] as $name) {
            $perm = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
            $this->admin->givePermissionTo($perm);
        }
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->admin);
        $this->getJson("/api/v1/admin/orders/{$order->id}/shipment")->assertOk();
        $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/update-status", [
            'status' => 'in_transit',
            'tracking_number' => 'TRK-1',
        ])->assertOk();
        $this->assertSame('in_transit', $this->latestOrderFor($this->user)->shipment_status);
        $this->assertSame('TRK-1', $this->latestOrderFor($this->user)->tracking_number);
    }
}
