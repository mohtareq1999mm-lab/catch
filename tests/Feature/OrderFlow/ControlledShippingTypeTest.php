<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
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
 * Controlled shipping types: exactly local|international.
 *
 * Anything else (same_day, express, hyperloop, malformed values) is
 * rejected at validation/resolution with 422 and creates nothing.
 * Replaces the former dynamic-type contract: the business decision is a
 * controlled discriminator, not a free-form identifier.
 */
class ControlledShippingTypeTest extends TestCase
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
            'email' => 'buyer-ctype@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now()]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin-ctype@example.com',
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
            foreach (['update-order-status', 'view-orders', 'view-order'] as $name) {
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

    private function statusIds(array $codes): array
    {
        return OrderStatus::query()->whereIn('code', $codes)->orderBy('id')->pluck('id')->all();
    }

    private function checkoutPayload(array $overrides = []): array
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

    private function freshCartWithProduct(): void
    {
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
            'shipping_method' => ShippingMethod::SCHEDULED,
            'is_gift' => false]);
        $cart->update(['total_price' => $this->product->price]);
    }

    public function test_only_local_and_international_resolve(): void
    {
        Sanctum::actingAs($this->user);

        $this->getJson('/api/v1/general/order-flows/by-shipping-type/local')->assertStatus(200);
        $this->getJson('/api/v1/general/order-flows/by-shipping-type/international')->assertStatus(200);

        foreach (['same_day', 'express', 'pickup', 'hyperloop'] as $type) {
            $this->getJson("/api/v1/general/order-flows/by-shipping-type/{$type}")->assertStatus(422);
        }
    }

    public function test_third_shipping_type_is_rejected_everywhere(): void
    {
        Sanctum::actingAs($this->admin);

        // Admin flow creation refuses non-listed types.
        $this->postJson('/api/v1/admin/order-flows', [
            'code' => 'same_day_flow',
            'name' => ['en' => 'Same Day', 'ar' => 'نفس اليوم'],
            'shipping_type' => 'same_day',
            'status_ids' => $this->statusIds(['pending', 'delivered']),
        ])->assertStatus(422);
        $this->assertSame(0, OrderFlow::query()->where('shipping_type', 'same_day')->count());

        // Checkout refuses it too, creating no order.
        $this->freshCartWithProduct();
        Sanctum::actingAs($this->user);
        $this->postJson('/api/v1/general/checkout', $this->checkoutPayload([
            'shipping_type' => 'same_day',
        ]))->assertStatus(422);
        $this->assertSame(0, Order::query()->where('user_id', $this->user->id)->count());
    }

    public function test_local_checkout_still_works(): void
    {
        $this->freshCartWithProduct();
        Sanctum::actingAs($this->user);

        $this->postJson('/api/v1/general/checkout', $this->checkoutPayload([
            'shipping_type' => 'local',
        ]))->assertStatus(200);

        $order = Order::query()->where('user_id', $this->user->id)->latest('id')->firstOrFail();
        $this->assertSame('local', $order->shipping_type);
        $this->assertSame('pending', $order->status);
    }
}
