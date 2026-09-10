<?php

namespace Tests\Feature;

use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Variation;
use Marvel\Database\Models\User;
use Marvel\Enums\ShippingMethod;
use Marvel\Enums\ProductType;
use Marvel\Enums\DiscountType;
use Tests\TestCase;

class CheckoutRegressionTest extends TestCase
{

    use DatabaseTransactions;

    private const PREFIX = '/api/v1/general';

    private User $user;
    private Product $product;
    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');
        Config::set('scout.driver', 'null');

        $this->createTestTables();
        $this->seedBaseData();
    }

    private function createTestTables(): void
    {
        if (Schema::hasTable('users')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

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

        Schema::create('variation_options', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained('attributes')->cascadeOnDelete();
            $table->string('value');
            $table->timestamps();
        });

        Schema::create('attribute_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_variant_id')->constrained('product_variants')->cascadeOnDelete();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();
            $table->timestamps();
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

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->date('start_date')->default(now());
            $table->date('end_date');
            $table->boolean('status')->default(true);
            $table->string('type')->default('percentage');
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->integer('order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('flash_sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained('flash_sales')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
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

        Schema::create('coupon_reservations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('order_id')->constrained()->onDelete('cascade');
            $table->timestamp('reserved_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->index(['coupon_id', 'expires_at']);
            $table->unique(['order_id']);
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

        $this->user = User::create([
            'name' => 'Regression User',
            'email' => 'regression@test.com',
            'password' => bcrypt('pass'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Regression Product',
            'slug' => 'reg-prod-' . Str::random(6),
            'price' => 100.00,
            'price_after_discount' => null,
            'price_after_flash_sale' => null,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 100,
        ]);
    }

    private function auth(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function createCartWithItem(int $quantity = 1, ?Product $product = null, bool $reserve = true): Cart
    {
        $product ??= $this->product;
        if ($reserve) {
            $product->increment('reserved_quantity', $quantity);
        }

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => $product->price * $quantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $product->price,
            'total_price' => $product->price * $quantity,
            'reserved_quantity' => $reserve ? $quantity : 0,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart->fresh();
    }

    private function checkoutPayload(): array
    {
        return [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => $this->governorate->id,
        ];
    }

    /** @test */
    public function checkout_uses_current_product_price_not_stale_cart_price(): void
    {
        $this->auth();
        $this->createCartWithItem(2);

        $cartItem = CartItem::where('cart_id', Cart::where('user_id', $this->user->id)->first()->id)->first();
        $this->assertEquals(100.00, (float) $cartItem->price);

        $this->product->update(['price' => 150.00]);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(300.00, (float) $order->price);
        $this->assertEquals(330.00, (float) $order->total_price);

        $orderItem = $order->orderItems()->first();
        $this->assertEquals(150.00, (float) $orderItem->product_price);
        $this->assertEquals(300.00, (float) $orderItem->product_total_price);
    }

    /** @test */
    public function checkout_recalculates_price_when_flash_sale_starts(): void
    {
        $this->auth();
        $this->createCartWithItem(1);

        $cartItem = CartItem::where('cart_id', Cart::where('user_id', $this->user->id)->first()->id)->first();
        $this->assertEquals(100.00, (float) $cartItem->price);

        $flashSale = FlashSale::create([
            'title' => 'Test Flash Sale',
            'slug' => 'test-fs-' . Str::random(6),
            'discount' => 20,
            'type' => 'percentage',
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        $flashSale->products()->attach($this->product->id);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(80.00, (float) $order->price);

        $orderItem = $order->orderItems()->first();
        $this->assertEquals(80.00, (float) $orderItem->product_flash_sale_price);
        $this->assertEquals(80.00, (float) $orderItem->product_price);
    }

    /** @test */
    public function checkout_uses_product_price_when_flash_sale_ends(): void
    {
        $this->auth();

        $flashSale = FlashSale::create([
            'title' => 'Ended Flash Sale',
            'slug' => 'ended-fs-' . Str::random(6),
            'discount' => 30,
            'type' => 'percentage',
            'start_date' => now()->subMonth(),
            'end_date' => now()->subDay(),
            'status' => true,
        ]);
        $flashSale->products()->attach($this->product->id);

        $productWithFsPrice = Product::create([
            'name' => 'FS Product',
            'slug' => 'fs-prod-' . Str::random(6),
            'price' => 200.00,
            'price_after_flash_sale' => 140.00,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);
        $flashSale->products()->attach($productWithFsPrice->id);

        $this->createCartWithItem(1, $productWithFsPrice);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);

        $orderItem = $order->orderItems()->first();
        $this->assertNull($orderItem->product_flash_sale_price);
        $this->assertEquals(200.00, (float) $orderItem->product_price);
    }

    /** @test */
    public function checkout_with_variant_uses_current_variant_price(): void
    {
        $this->auth();

        $variant = $this->product->variations()->create([
            'sku' => 'VREG-' . Str::random(6),
            'price' => 250.00,
            'stock_quantity' => 20,
        ]);

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 250.00,
        ]);
        $variant->increment('reserved_quantity', 1);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'price' => 250.00,
            'total_price' => 250.00,
            'reserved_quantity' => 1,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        $variant->update(['price' => 300.00]);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(300.00, (float) $order->price);

        $orderItem = $order->orderItems()->first();
        $this->assertEquals(300.00, (float) $orderItem->product_price);
    }

    /** @test */
    public function checkout_refreshes_promotion_price_from_current_data(): void
    {
        $this->auth();
        $this->createCartWithItem(2);

        $coupon = Coupon::create([
            'name' => 'Regression Coupon',
            'slug' => 'reg-cpn-' . Str::random(4),
            'code' => 'REGRESSION10',
            'discount_type' => 'fixed_rate',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $this->product->update(['price' => 200.00]);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals(400.00, (float) $order->price);
        $this->assertEquals(10.00, (float) $order->coupon_discount);
        $this->assertEquals(420.00, (float) $order->total_price);
    }

    /** @test */
    public function checkout_rejects_expired_coupon_and_clears_it(): void
    {
        $this->auth();
        $this->createCartWithItem(1);

        $coupon = Coupon::create([
            'name' => 'Expired Coupon',
            'slug' => 'exp-cpn-' . Str::random(4),
            'code' => 'EXPIRED',
            'discount_type' => 'fixed_rate',
            'discount' => 50,
            'status' => true,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $cart->refresh();
        $this->assertNull($cart->coupon);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNull($order->coupon);
        $this->assertEquals(130.00, (float) $order->total_price);
    }

    /** @test */
    public function checkout_stores_price_snapshot_immutable(): void
    {
        $this->auth();
        $this->createCartWithItem(1);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $savedPrice = (float) $order->price;
        $savedTotal = (float) $order->total_price;
        $orderItemPrice = (float) $order->orderItems()->first()->product_price;

        $this->product->update(['price' => 999.99]);

        $order->refresh();
        $this->assertEquals($savedPrice, (float) $order->price);
        $this->assertEquals($savedTotal, (float) $order->total_price);
        $this->assertEquals($orderItemPrice, (float) $order->orderItems()->first()->product_price);
    }

    /** @test */
    public function deductStock_decrements_correct_variation_column(): void
    {
        $repo = app(\Marvel\Database\Repositories\OrderRepository::class);
        $refMethod = new \ReflectionMethod($repo, 'deductStock');

        $variant = \Marvel\Database\Models\Variation::create([
            'product_id' => $this->product->id,
            'sku' => 'DEDUCT-' . Str::random(6),
            'price' => 100.00,
            'stock_quantity' => 10,
        ]);

        $refMethod->invoke($repo, [
            ['product_id' => $this->product->id, 'order_quantity' => 3, 'variation_option_id' => $variant->id],
        ]);

        $variant->refresh();
        $this->assertEquals(7, $variant->stock_quantity);
    }

    /** @test */
    public function checkout_coupon_locked_during_validation(): void
    {
        $this->auth();
        $this->createCartWithItem(1);

        $coupon = Coupon::create([
            'name' => 'Lock Test',
            'slug' => 'lock-cpn-' . Str::random(4),
            'code' => 'LOCKTEST',
            'discount_type' => 'fixed_rate',
            'discount' => 20,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'limiter' => 10,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => $coupon->code]);

        $response = $this->postJson(self::PREFIX . '/checkout', $this->checkoutPayload());
        $response->assertStatus(200);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNotNull($order);
        $this->assertEquals($coupon->code, $order->coupon);
        $this->assertEquals(20.00, (float) $order->coupon_discount);
    }
}
