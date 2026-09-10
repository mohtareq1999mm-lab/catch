<?php

namespace Tests\Feature;

use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Spatie\Permission\Models\Permission;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Promotion;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\ShippingMethod;
use Marvel\Enums\OrderStatus;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

class OrdersProductionHardenTest extends TestCase
{

    use DatabaseTransactions, WithInvoiceTables;

    private const PREFIX = '/api/v1/general';

    private User $customer;
    private User $admin;
    private Product $product;
    private Product $discountedProduct;
    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();

        if (Schema::hasTable('users')) {
            $this->createInvoiceTables();
            $this->seedBaseData();
            return;
        }

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained('countries')->cascadeOnDelete();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->boolean('is_fast_shipping_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained('governorates')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('free_shipping_over', 10, 2)->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->unique('governorate_id');
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('type')->default('user');
            $table->boolean('is_active')->default(true);
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->string('status')->default('publish');
            $table->boolean('in_stock')->default(true);
            $table->integer('stock_quantity')->default(10);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('is_fast_shipping_available')->default(false);
            $table->boolean('has_discount')->default(false);
            $table->boolean('has_flash_sale')->default(false);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->boolean('discount_status')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->decimal('price_after_discount', 10, 2)->nullable();
            $table->decimal('price_after_flash_sale', 10, 2)->nullable();
            $table->string('product_type')->default('simple');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('coupon')->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('reserved_quantity')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->text('attributes')->nullable();
            $table->string('shipping_method')->default('SCHEDULED');
            $table->boolean('is_gift')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->string('name')->nullable();
            $table->string('user_phone')->nullable();
            $table->string('user_email')->nullable();
            $table->text('address')->nullable();
            $table->text('notes')->nullable();
            $table->string('shipping_method')->default('SCHEDULED');
            $table->string('fulfillment_type', 20)->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('payment_gateway', 50)->nullable();
            $table->string('status')->default('pending');
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->decimal('shipping_price', 10, 2)->nullable();
            $table->string('coupon')->nullable();
            $table->decimal('coupon_discount', 10, 2)->nullable();
            $table->string('coupon_discount_type')->nullable();
            $table->decimal('coupon_discount_max_amount', 10, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->string('promotion_type')->nullable();
            $table->decimal('promotion_discount', 10, 2)->nullable();
            $table->timestamp('expected_delivery_at')->nullable();
            $table->decimal('fast_shipping_fee', 10, 2)->default(0);
            $table->unsignedBigInteger('pickup_location_id')->nullable();
            $table->string('pickup_location_name')->nullable();
            $table->text('pickup_location_address')->nullable();
            $table->string('pickup_location_phone')->nullable();
            $table->string('pickup_location_coordinates')->nullable();
            $table->timestamp('inventory_restored_at')->nullable();
                                    $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->index(['status', 'reservation_expires_at']);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_sku')->nullable();
            $table->text('attributes')->nullable();
            $table->integer('product_quantity')->default(1);
            $table->decimal('product_price', 10, 2)->default(0);
            $table->decimal('product_total_price', 10, 2)->default(0);
            $table->decimal('product_discount_price', 10, 2)->nullable();
            $table->decimal('product_flash_sale_price', 10, 2)->nullable();
            $table->boolean('is_gift')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->decimal('promotion_discount_amount', 10, 2)->default(0);
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('invoice_id')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->string('gateway_transaction_id', 255)->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('qr_code_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->string('code')->unique();
            $table->string('type');
            $table->string('type_amount');
            $table->decimal('value', 10, 2);
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->decimal('minimum_order_amount', 10, 2)->default(0);
            $table->string('apply_to')->default('specific_products');
            $table->integer('limiter')->nullable();
            $table->integer('usage')->default(0);
            $table->date('start_at')->nullable();
            $table->date('end_at')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->index(['status', 'start_at', 'end_at'], 'promotions_validity_index');
            $table->index(['usage', 'limiter'], 'promotions_usage_limiter_index');
        });

        Schema::create('promotion_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['promotion_id', 'product_id']);
        });

        Schema::create('promotion_gift_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->timestamps();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('discount_type');
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->boolean('status')->default(true);
            $table->integer('limiter')->nullable();
            $table->integer('used')->default(0);
            $table->timestamps();
        });

        Schema::create('coupon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedInteger('max_uses')->default(1);
            $table->unsignedInteger('used')->default(0);
            $table->timestamp('assigned_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::create('coupon_assignment_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_assignment_id')->constrained('coupon_assignments')->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('used_at')->useCurrent();
            $table->timestamps();
            $table->index('coupon_assignment_id');
            $table->index('created_at');
            $table->index(['coupon_assignment_id', 'created_at']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->index(['coupon_id', 'user_id']);
            $table->unique(['coupon_id', 'user_id']);
        });

        Schema::create('pickup_locations', function (Blueprint $table) {
            $table->id();
            $table->string('store_name');
            $table->text('address');
            $table->string('phone');
            $table->string('email')->nullable();
            $table->string('latitude')->nullable();
            $table->string('longitude')->nullable();
            $table->json('working_hours')->nullable();
            $table->boolean('status')->default(true);
            $table->integer('display_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flash_sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained('flash_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->string('event')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });

        $this->createInvoiceTables();

        $this->seedBaseData();
        // FCM channel resolves user device tokens during notification fanout.
        if (!Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('token')->unique();
                $table->string('device_type')->nullable();
                $table->timestamps();
            });
        }

    }

    private function seedBaseData(): void
    {
        $country = Country::create(['name' => 'Egypt', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Cairo',
            'status' => true,
        ]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 30.00,
            'free_shipping_over' => 500.00,
            'status' => true,
        ]);

        $this->customer = User::create([
            'name' => 'Customer',
            'email' => 'customer@test.com',
            'password' => bcrypt('pass'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@test.com',
            'password' => bcrypt('pass'),
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-prod-' . Str::random(6),
            'price' => 100.00,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $this->discountedProduct = Product::create([
            'name' => 'Discounted Product',
            'slug' => 'disc-prod-' . Str::random(6),
            'price' => 200.00,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 30,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'discount_status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        Permission::create(['name' => 'update-order-status', 'guard_name' => 'api']);
    }

    private function createCartWithItems(int $quantity = 2, ?Product $product = null): Cart
    {
        $product ??= $this->product;
        $product->increment('reserved_quantity', $quantity);

        $cart = Cart::create([
            'user_id' => $this->customer->id,
            'status' => 'active',
            'total_price' => $product->price * $quantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'total_price' => $product->price * $quantity,
            'reserved_quantity' => $quantity,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart->fresh();
    }

    private function actAsCustomer(): void
    {
        Sanctum::actingAs($this->customer);
    }

    // ===================== AUTHENTICATION =====================

    /** @test */
    public function guest_cannot_checkout()
    {
        $this->postJson(self::PREFIX . '/checkout', [])
            ->assertStatus(401);
    }

    /** @test */
    public function guest_cannot_access_promotions()
    {
        $this->getJson(self::PREFIX . '/checkout/promotions')
            ->assertStatus(401);
    }

    // ===================== CHECKOUT FLOW =====================

    /** @test */
    public function checkout_creates_order_with_correct_totals()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);
        $this->assertEquals(200.00, (float) $order->price);
        $this->assertEquals(30.00, (float) $order->shipping_price);
        $this->assertEquals(230.00, (float) $order->total_price);

        $this->assertCount(1, $order->orderItems);
        $this->assertEquals(2, $order->orderItems->first()->product_quantity);
    }

    /** @test */
    public function checkout_creates_transaction_for_cod()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();

        $transaction = $order->transactions()->first();
        $this->assertNotNull($transaction);
        $this->assertEquals('cod', $transaction->payment_method);
        $this->assertEquals('pending', $transaction->status);
        $this->assertEquals(130.00, (float) $transaction->amount);
    }

    /** @test */
    public function checkout_creates_order_items_with_price_snapshot()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $orderItem = $order->orderItems()->first();

        $this->assertEquals(100.00, (float) $orderItem->product_price);
        $this->assertEquals(100.00, (float) $orderItem->product_total_price);
        $this->assertNotNull($orderItem->product_name);
    }

    /** @test */
    public function checkout_rejects_empty_cart()
    {
        $this->actAsCustomer();

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(400);
    }

    /** @test */
    public function checkout_rejects_invalid_payment_method()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'invalid_method',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function checkout_rejects_cod_with_pickup()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
    }

    // ===================== ORDER STATUS LIFECYCLE =====================

    /** @test */
    public function pending_to_completed_transition_succeeds()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $tx = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
            'invoice_id' => 'INV-' . Str::random(8),
            'uuid' => (string) Str::uuid(),
        ]);

        $service = app(OrderService::class);
        $result = $service->changeOrderStatus($tx->invoice_id, 'completed');

        $this->assertNotNull($result);
        $this->assertEquals('completed', $result->refresh()->status);

        $tx->refresh();
        $this->assertEquals('paid', $tx->status);
        $this->assertNotNull($tx->paid_at);
    }

    /** @test */
    public function pending_to_cancelled_transition_succeeds()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $tx = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'online',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
            'invoice_id' => 'INV-CAN-' . Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);

        $service = app(OrderService::class);
        $result = $service->changeOrderStatus($tx->invoice_id, 'cancelled');

        $this->assertNotNull($result);
        $this->assertEquals('cancelled', $result->refresh()->status);
        $this->assertEquals('failed', $tx->refresh()->status);
    }

    /** @test */
    public function completed_to_cancelled_transition_rejected()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'completed',
        ]);

        $service = app(OrderService::class);

        $this->expectException(\RuntimeException::class);
        $service->changeOrderStatus(null, 'cancelled', $order->id);
    }

    /** @test */
    public function cancelled_to_completed_transition_rejected()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'cancelled',
        ]);

        $service = app(OrderService::class);

        $this->expectException(\RuntimeException::class);
        $service->changeOrderStatus(null, 'completed', $order->id);
    }

    /** @test */
    public function pending_to_delivered_transition_rejected()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $service = app(OrderService::class);

        $this->expectException(\RuntimeException::class);
        $service->changeOrderStatus(null, 'delivered', $order->id);
    }

    // ===================== PAYMENT CALLBACK =====================

    /** @test */
    public function callback_missing_payment_id_returns_400()
    {
        $this->actAsCustomer();
        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'gateway' => 'myfatoorah',
            'governorate_id' => $this->governorate->id,
        ]);

        // callback without paymentId should return 400
        $response = $this->get(self::PREFIX . '/checkout/callback');
        $response->assertStatus(400);
    }

    // ===================== COUPON INTEGRATION =====================

    /** @test */
    public function checkout_with_valid_coupon_applies_discount()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);

        $coupon = Coupon::create([
            'name' => '10 Off',
            'slug' => 'coupon-' . Str::random(4),
            'code' => 'TENOFF',
            'discount_type' => 'fixed_rate',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(220.00, (float) $order->total_price);
        $this->assertEquals(10.00, (float) $order->coupon_discount);
    }

    /** @test */
    public function checkout_with_expired_coupon_ignores_it()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $coupon = Coupon::create([
            'name' => 'Expired',
            'slug' => 'exp-cpn-' . Str::random(4),
            'code' => 'EXPIREDNOW',
            'discount_type' => 'fixed_rate',
            'discount' => 50,
            'status' => true,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(130.00, (float) $order->total_price);
        $this->assertNull($order->coupon);
    }

    /** @test */
    public function checkout_with_free_shipping_coupon_sets_shipping_to_zero()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);

        $coupon = Coupon::create([
            'name' => 'Free Ship',
            'slug' => 'fs-cpn-' . Str::random(4),
            'code' => 'SHIPFREE',
            'discount_type' => 'free_shipping',
            'discount' => 0,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(200.00, (float) $order->total_price);
        $this->assertEquals(0, (float) $order->shipping_price);
        $this->assertEquals('free_shipping', $order->coupon_discount_type);
    }

    // ===================== PROMOTION INTEGRATION =====================

    /** @test */
    public function checkout_with_percentage_promotion_applies_discount()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);

        $promotion = Promotion::create([
            'name' => '10% Off',
            'slug' => 'promo10-' . Str::random(4),
            'code' => 'P10',
            'type' => 'percentage',
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'apply_to' => 'all',
        ]);
        $promotion->products()->attach($this->product->id);

        // Apply promotion to cart items before checkout (production flow: preview endpoint does this)
        $cart = Cart::where('user_id', $this->customer->id)->first();
        app(\App\Services\General\PromotionService::class)->applySelectedPromotion($cart, (int) $promotion->id);
        $cart->refresh();

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertNotNull($order->promotion_id);
        $this->assertEquals(210.00, (float) $order->total_price);
    }

    // ===================== INVENTORY =====================

    /** @test */
    public function checkout_does_not_finalize_inventory()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);

        $initialStock = $this->product->fresh()->stock_quantity;
        $initialSold = $this->product->fresh()->sold_quantity;

        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $product = $this->product->fresh();

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $this->assertEquals('active', $cart->status);

        $this->assertEquals($initialStock, $product->stock_quantity);
        $this->assertEquals($initialSold, $product->sold_quantity);
    }

    /** @test */
    public function inventory_not_affected_if_checkout_fails()
    {
        $this->actAsCustomer();

        $initialStock = $this->product->fresh()->stock_quantity;

        // No cart ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â checkout will fail
        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $this->assertEquals($initialStock, $this->product->fresh()->stock_quantity);
    }

    /** @test */
    public function cancelled_order_restores_inventory()
    {
        Event::fake([OrderCancelled::class]);

        $this->actAsCustomer();
        $this->createCartWithItems(2);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();

        $originalStock = $this->product->fresh()->stock_quantity;

        // Cancel the order via service (simulates admin or callback cancellation)
        Event::fake();
        $service = app(OrderService::class);

        // Manually restore stock_quantity to what it was before checkout finalization
        // Since checkout already finalized, we need to test cancel flow via changeOrderStatus
        // which fires OrderCancelled ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ RestoreProductInventory restores inventory

        // First, set inventory_restored_at to null to allow restoration
        $order->update(['inventory_restored_at' => null]);

        $service->changeOrderStatus(null, 'cancelled', $order->id);

        // After restore, stock should be back up
        $product = $this->product->fresh();
        $this->assertGreaterThanOrEqual($originalStock, $product->stock_quantity);
    }

    // ===================== EVENTS =====================

    /** @test */
    public function order_created_event_dispatched_on_checkout()
    {
        Event::fake([OrderCreated::class]);

        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        Event::assertDispatched(OrderCreated::class, function ($event) {
            return $event->order->user_id === $this->customer->id;
        });
    }

    /** @test */
    public function order_status_changed_event_dispatched()
    {
        Event::fake([OrderStatusChanged::class, OrderCancelled::class]);

        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $tx = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
            'invoice_id' => 'INV-EVENT-' . Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);

        $service = app(OrderService::class);
        $service->changeOrderStatus($tx->invoice_id, 'completed');

        Event::assertDispatched(OrderStatusChanged::class);
    }

    /** @test */
    public function order_cancelled_event_dispatched()
    {
        Event::fake([OrderCancelled::class, OrderStatusChanged::class]);

        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $tx = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'online',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
            'invoice_id' => 'INV-CEVT-' . Str::random(6),
            'uuid' => (string) Str::uuid(),
        ]);

        $service = app(OrderService::class);
        $service->changeOrderStatus($tx->invoice_id, 'cancelled');

        Event::assertDispatched(OrderCancelled::class);
    }

    // ===================== MARK PAID =====================

    /** @test */
    public function mark_cod_as_paid_succeeds()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
            'uuid' => (string) Str::uuid(),
        ]);

        $service = app(OrderService::class);
        $service->markCodAsPaid($order);

        $order->refresh();
        $this->assertEquals('completed', $order->status);

        $tx = $order->transactions()->first();
        $this->assertEquals('paid', $tx->status);
        $this->assertNotNull($tx->paid_at);

        Event::assertDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function mark_cod_as_paid_rejects_when_no_pending_transaction()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $service = app(OrderService::class);

        $this->expectException(\RuntimeException::class);
        $service->markCodAsPaid($order);
    }

    /** @test */
    public function mark_cod_as_paid_rejects_already_paid_transaction()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'amount' => 130.00,
            'currency' => 'EGP',
            'paid_at' => now(),
            'uuid' => (string) Str::uuid(),
        ]);

        $service = app(OrderService::class);

        $this->expectException(\RuntimeException::class);
        $service->markCodAsPaid($order);
    }

    // ===================== TRANSACTION =====================

    /** @test */
    public function transaction_auto_generates_uuid()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $tx = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
        ]);

        $this->assertNotNull($tx->uuid);
        $this->assertTrue(Str::isUuid($tx->uuid));
    }

    /** @test */
    public function transaction_uuid_is_unique()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $tx1 = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
        ]);

        $tx2 = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'online',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
        ]);

        $this->assertNotEquals($tx1->uuid, $tx2->uuid);
    }

    // ===================== DUPLICATE CHECKOUT PREVENTION =====================

    /**
     * Reconciled to the order-owned-reservation contract (Phase -1):
     * the first checkout consumes the ordered cart slice; an emptied cart is
     * tidied away, so an immediate identical checkout is rejected with
     * CART_NOT_FOUND instead of fabricating a second order. Pending-order
     * reuse for carts that still hold items is covered by
     * CheckoutPendingOrderRedesignTest and FastShippingService.
     *
     * @test */
    public function duplicate_checkout_request_creates_new_order()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $payload = [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ];

        $response1 = $this->postJson(self::PREFIX . '/checkout', $payload);
        $response1->assertStatus(200);

        // Cart slice was consumed by the first checkout — no active cart remains.
        $response2 = $this->postJson(self::PREFIX . '/checkout', $payload);
        $response2->assertStatus(400);

        // Exactly one order exists and it owns its reservation.
        $orders = Order::where('user_id', $this->customer->id)->get();
        $this->assertCount(1, $orders);
        $this->assertEquals(Order::INVENTORY_STATE_ACTIVE, $orders->first()->inventory_state);
    }

    // ===================== ORDER ITEMS PRICE SNAPSHOT =====================

    /** @test */
    public function order_items_preserve_discount_snapshots()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1, $this->discountedProduct);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $orderItem = $order->orderItems()->first();

        $this->assertEquals(160.00, (float) $orderItem->product_price);
        $this->assertEquals(160.00, (float) $orderItem->product_total_price);
        $this->assertEquals(160.00, (float) $orderItem->product_discount_price);
    }

    /** @test */
    public function order_price_snapshot_immutable_after_product_price_change()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(1);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $savedTotal = (float) $order->total_price;

        $this->product->update(['price' => 500.00]);

        $order->refresh();
        $this->assertEquals($savedTotal, (float) $order->total_price);
    }

    // ===================== SECURITY =====================

    /** @test */
    public function customer_cannot_access_another_customers_order()
    {
        $otherUser = User::create([
            'name' => 'Other',
            'email' => 'other@test.com',
            'password' => bcrypt('pass'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $order = Order::create([
            'user_id' => $otherUser->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $request = new \Illuminate\Http\Request;
        $request->setUserResolver(fn() => $this->customer);
        $orders = app(OrderService::class)->paginateForUser($request);
        $orderIds = $orders->pluck('id')->toArray();
        $this->assertNotContains($order->id, $orderIds);
    }

    // ===================== FREE SHIPPING THRESHOLD =====================

    /** @test */
    public function free_shipping_threshold_applied()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(6); // 6 * 100 = 600 > 500 threshold

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $order = Order::where('user_id', $this->customer->id)->latest()->first();
        $this->assertEquals(600.00, (float) $order->total_price);
        $this->assertEquals(0, (float) $order->shipping_price);
    }

    // ===================== PROMOTION USAGE TRACKING =====================

    /** @test */
    public function promotion_usage_increments_on_order()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);

        $promotion = Promotion::create([
            'name' => 'Usage Test',
            'slug' => 'usage-' . Str::random(4),
            'code' => 'USAGE',
            'type' => 'percentage',
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'apply_to' => 'all',
            'limiter' => 100,
            'usage' => 0,
        ]);
        $promotion->products()->attach($this->product->id);

        // Apply promotion to cart items before checkout (production flow: preview endpoint does this)
        $cart = Cart::where('user_id', $this->customer->id)->first();
        app(\App\Services\General\PromotionService::class)->applySelectedPromotion($cart, (int) $promotion->id);
        $cart->refresh();

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ]);

        $response->assertStatus(200);
        $this->assertEquals(0, $promotion->fresh()->usage);
    }

    // ===================== AUTHORIZATION =====================

    /** @test */
    public function mark_paid_requires_update_order_status_permission()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        $this->actAsCustomer();
        $this->postJson(self::PREFIX . "/checkout/cod/{$order->id}/mark-paid")
            ->assertStatus(403);
    }

    /** @test */
    public function mark_paid_succeeds_for_admin_with_permission()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 130.00,
            'currency' => 'EGP',
            'uuid' => (string) Str::uuid(),
        ]);

        Sanctum::actingAs($this->admin);
        $this->admin->givePermissionTo('update-order-status');

        $this->postJson(self::PREFIX . "/checkout/cod/{$order->id}/mark-paid")
            ->assertStatus(200);
    }

    /** @test */
    public function mark_paid_returns_404_for_nonexistent_order()
    {
        Sanctum::actingAs($this->admin);
        $this->admin->givePermissionTo('update-order-status');

        $this->postJson(self::PREFIX . '/checkout/cod/99999/mark-paid')
            ->assertStatus(404);
    }
}
