<?php

namespace Tests\Feature;

use App\DTOs\GatewayResult;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\Payment\PaymentGatewayFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\PickupLocation;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

class PaymentCheckoutTest extends TestCase
{

    use DatabaseTransactions, WithInvoiceTables;

    private const PREFIX = '/api/v1';

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        Config::set('scout.driver', 'null');
        Config::set('payment.default_currency', 'EGP');

        if (!Schema::hasTable('products')) {
            $this->createAllTables();
        }

        $this->createInvoiceTables();

        $this->seedBaseData();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function createAllTables(): void
    {
        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });


        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->boolean('in_stock')->default(true);
            $table->integer('stock_quantity')->default(10);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('is_fast_shipping_available')->default(false);
            $table->boolean('has_discount')->default(false);
            $table->boolean('has_flash_sale')->default(false);
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->string('product_type')->default('simple');
            $table->decimal('height', 8, 2)->nullable();
            $table->decimal('width', 8, 2)->nullable();
            $table->decimal('length', 8, 2)->nullable();
            $table->decimal('weight', 8, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->string('type')->default('user');
            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('user_preferences', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('currency_code', 3)->nullable();
            $table->timestamps();
            $table->unique('user_id');
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3)->unique();
            $table->json('name');
            $table->json('symbol')->nullable();
            $table->json('country_name')->nullable();
            $table->string('numeric_code', 3)->nullable();
            $table->unsignedTinyInteger('decimal_places')->default(2);
            $table->string('icon')->nullable();
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('currency_id')->constrained('currencies')->cascadeOnDelete();
            $table->decimal('exchange_rate', 20, 10);
            $table->date('effective_date');
            $table->timestamps();
            $table->unique(['currency_id', 'effective_date']);
            $table->index('effective_date');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('countries', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('country_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->boolean('is_fast_shipping_enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('shipping_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->integer('estimated_days')->nullable();
            $table->decimal('free_shipping_over', 10, 2)->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('general');
            $table->text('icon')->nullable();
            $table->integer('parent_id')->nullable();
            $table->integer('level')->default(0);
            $table->boolean('status')->default(true);
            $table->boolean('is_featured')->default(false);
            $table->timestamps();
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('brands', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('status')->default(true);
            $table->string('type')->default('general');
            $table->integer('order')->default(0);
            $table->timestamps();
        });

        Schema::create('brand_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('brand_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->string('type')->default('general');
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('banner_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('banner_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('sliders', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->boolean('status')->default(true);
            $table->string('type')->default('general');
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('slider_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('slider_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('type')->default('general');
            $table->decimal('discount', 10, 2)->nullable();
            $table->integer('order')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flash_sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('slug')->unique();
            $table->string('type')->default('general');
            $table->string('discount_type')->nullable();
            $table->decimal('amount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->integer('usage_limit')->nullable();
            $table->integer('usage')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
            $table->timestamps();
        });

        Schema::create('carts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('coupon')->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->string('status')->default('active');
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('cart_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cart_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->integer('quantity')->default(1);
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->text('attributes')->nullable();
            $table->integer('reserved_quantity')->default(0);
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('shipping_method')->default('SCHEDULED');
            $table->boolean('is_gift')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->timestamps();
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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
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
            $table->unsignedBigInteger('pickup_location_id')->nullable();
            $table->string('pickup_location_name')->nullable();
            $table->text('pickup_location_address')->nullable();
            $table->string('pickup_location_phone')->nullable();
            $table->string('pickup_location_coordinates')->nullable();
            $table->dateTime('expected_delivery_at')->nullable();
            $table->decimal('fast_shipping_fee', 10, 2)->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('shipping_price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->default(0);
            $table->string('coupon')->nullable();
            $table->decimal('coupon_discount', 10, 2)->nullable();
            $table->string('coupon_discount_type')->nullable();
            $table->decimal('coupon_discount_max_amount', 10, 2)->nullable();
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->string('promotion_code')->nullable();
            $table->string('promotion_type')->nullable();
            $table->decimal('promotion_discount', 10, 2)->nullable();
            $table->string('status')->default('pending');
                                    $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->index(['status', 'reservation_expires_at']);
            $table->timestamps();
            $table->softDeletes();
            $table->timestamp('inventory_restored_at')->nullable();
        });

        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_sku')->nullable();
            $table->text('attributes')->nullable();
            $table->integer('product_quantity')->default(1);
            $table->decimal('product_price', 10, 2)->default(0);
            $table->decimal('product_total_price', 10, 2)->default(0);
            $table->decimal('product_discount_price', 10, 2)->nullable();
            $table->decimal('promotion_discount_amount', 10, 2)->default(0);
            $table->decimal('product_flash_sale_price', 10, 2)->nullable();
            $table->boolean('is_gift')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->timestamps();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_id')->nullable();
            $table->string('payment_method')->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->string('gateway_transaction_id', 255)->nullable();
            $table->json('gateway_response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('qr_code_url', 500)->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->nullable();
            $table->string('code')->nullable();
            $table->decimal('discount', 10, 2)->nullable();
            $table->string('discount_type')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('current_price', 10, 2)->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->nullable();
            $table->json('image')->nullable();
            $table->boolean('status')->default(true);
            $table->string('language')->default('en');
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->morphs('model');
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('disk')->default('public');
            $table->unsignedBigInteger('size')->default(0);
            $table->json('manipulations')->nullable();
            $table->json('custom_properties')->nullable();
            $table->json('generated_conversions')->nullable();
            $table->json('responsive_images')->nullable();
            $table->unsignedInteger('order_column')->nullable();
            $table->nullableTimestamps();
        });

        Schema::create('attributes', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('attribute_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_id')->constrained()->cascadeOnDelete();
            $table->string('value');
            $table->string('slug')->nullable();
            $table->timestamps();
        });

        Schema::create('attribute_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attribute_value_id')->constrained('attribute_values')->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->cascadeOnDelete();
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

        Schema::create('tags', function (Blueprint $table) {
            $table->id();
            $table->text('name');
            $table->string('slug')->unique();
            $table->timestamps();
        });

        Schema::create('product_tag', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->timestamps();
        });

        $this->createInvoiceTables();
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
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'description' => 'A test product',
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => false,
        ]);

        Settings::create([
            'language' => 'en',
            'options' => [],
        ]);

        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);
        Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
        ]);
        \Marvel\Database\Models\ShippingPrice::create([
            'governorate_id' => 1,
            'price' => 50.00,
            'estimated_days' => 3,
            'free_shipping_over' => 500.00,
            'status' => true,
        ]);
    }

    private function createActiveCart(): Cart
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 100.00,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 1,
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_method' => 'SCHEDULED',
        ]);

        $cart->load(['items', 'items.product', 'items.productVariant']);
        return $cart;
    }

    private function createActiveCartForFast(): Cart
    {
        $fastProduct = Product::create([
            'name' => 'Fast Product',
            'slug' => 'fast-product-' . Str::random(6),
            'price' => 150.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => true,
        ]);

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 150.00,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $fastProduct->id,
            'quantity' => 1,
            'price' => 150.00,
            'total_price' => 150.00,
            'shipping_method' => 'FAST',
        ]);

        $cart->load(['items', 'items.product', 'items.productVariant']);
        return $cart;
    }

    private function createPickupLocation(): PickupLocation
    {
        return PickupLocation::create([
            'store_name' => 'Main Store',
            'address' => '123 Main St',
            'phone' => '01000000000',
            'status' => true,
        ]);
    }

    // ========== Scheduled Checkout: Backward Compat (no new fields) ==========

    /** @test */
    public function scheduled_checkout_without_payment_fields_defaults_to_online_myfatoorah()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $mockService = \Mockery::mock(\App\Services\General\MyfatoraService::class);
        $mockService->shouldReceive('createInvoice')
            ->once()
            ->andReturn([
                'Data' => [
                    'InvoiceURL' => 'https://example.com/pay',
                    'InvoiceId' => 12345,
                ],
            ]);
        $this->app->instance(\App\Services\General\MyfatoraService::class, $mockService);

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['url']]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
        ]);

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals('pending', $order->status);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'currency' => 'EGP',
        ]);
    }

    // ========== Scheduled Checkout: COD ==========

    /** @test */
    public function scheduled_checkout_with_cod_returns_order_id()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['order_id']]);
        $response->assertJsonMissing(['data' => ['url']]);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
        ]);

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => $order->total_price,
            'currency' => 'EGP',
        ]);
    }

    /** @test */
    public function scheduled_checkout_with_cod_has_no_url_in_response()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertArrayNotHasKey('url', $data);
        $this->assertArrayHasKey('order_id', $data);
    }

    // ========== Scheduled Checkout: Pay at Cashier ==========

    /** @test */
    public function scheduled_checkout_with_pay_at_cashier_requires_pickup_location()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();
        $this->createPickupLocation();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['order_id']]);
        $this->assertArrayNotHasKey('qr_code', $response->json('data'));
        $this->assertArrayNotHasKey('transaction_uuid', $response->json('data'));

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertEquals('pay_at_cashier', $order->payment_method);
        $this->assertEquals('pickup', $order->fulfillment_type);
        $this->assertEquals(1, $order->pickup_location_id);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'pay_at_cashier',
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function pay_at_cashier_lifecycle_is_preserved_after_qr_removal()
    {
        Event::fake([\App\Events\PaymentSucceeded::class]);

        Sanctum::actingAs($this->user);
        $this->createActiveCart();
        $this->createPickupLocation();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => 1,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['order_id']]);
        $this->assertArrayNotHasKey('qr_code', $response->json('data'));
        $this->assertArrayNotHasKey('transaction_uuid', $response->json('data'));

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertNotNull($order);
        $this->assertEquals('pay_at_cashier', $order->payment_method);
        $this->assertEquals('pending', $order->status);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'pay_at_cashier',
            'status' => 'pending',
        ]);

        $transaction = $order->transactions()->where('payment_method', 'pay_at_cashier')->first();
        $this->assertNotNull($transaction->uuid);
        $this->assertTrue(Str::isUuid($transaction->uuid));

        app(\App\Services\General\OrderService::class)->markCashierPaid($order);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'pay_at_cashier',
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);

        Event::assertDispatched(\App\Events\PaymentSucceeded::class, fn ($e) => $e->order->id === $order->id);
    }

    // ========== COD + Pickup Rejection ==========

    /** @test */
    public function cod_with_pickup_is_rejected()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();
        $this->createPickupLocation();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function fast_checkout_cod_with_pickup_is_rejected()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCartForFast();

        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);
        Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
            'is_fast_shipping_enabled' => true,
        ]);

        Settings::truncate();
        Settings::create([
            'language' => 'en',
            'options' => [
                'fast_shipping' => [
                    'enabled' => true,
                    'duration_minutes' => 120,
                    'fee' => 25,
                    'start_hour' => '08:00',
                    'end_hour' => '22:00',
                ],
            ],
        ]);

        $this->createPickupLocation();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => 1,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('success', false);
    }

    // ========== Fast Checkout: COD ==========

    /** @test */
    public function fast_checkout_with_cod_returns_order_id()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCartForFast();

        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);
        Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
            'is_fast_shipping_enabled' => true,
        ]);

        Settings::truncate();
        Settings::create([
            'language' => 'en',
            'options' => [
                'fast_shipping' => [
                    'enabled' => true,
                    'duration_minutes' => 120,
                    'fee' => 25,
                    'start_hour' => '08:00',
                    'end_hour' => '22:00',
                ],
            ],
        ]);

        \Carbon\Carbon::setTestNow(\Carbon\Carbon::parse('2026-09-01 12:00:00'));
        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);
        \Carbon\Carbon::setTestNow();
        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['data' => ['order_id']]);
        $response->assertJsonMissing(['data' => ['url']]);

        $order = Order::where('user_id', $this->user->id)->first();
        $this->assertEquals('cod', $order->payment_method);
        $this->assertEquals('FAST', $order->shipping_method);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'cod',
            'status' => 'pending',
        ]);
    }

    // ========== Transaction Model: UUID Generation ==========

    /** @test */
    public function transaction_auto_generates_uuid_on_creation()
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'total_price' => 100,
            'status' => 'pending',
        ]);

        $transaction = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
        ]);

        $this->assertNotNull($transaction->uuid);
        $this->assertTrue(Str::isUuid($transaction->uuid));
    }

    /** @test */
    public function transaction_uuid_is_unique()
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'total_price' => 100,
            'status' => 'pending',
        ]);

        $t1 = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
        ]);

        $t2 = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
        ]);

        $this->assertNotEquals($t1->uuid, $t2->uuid);
    }

    // ========== Transaction Scopes ==========

    /** @test */
    public function transaction_pending_scope_filters_correctly()
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'total_price' => 100,
            'status' => 'pending',
        ]);

        Transaction::create(['order_id' => $order->id, 'user_id' => $this->user->id, 'payment_method' => 'cod', 'status' => 'pending']);
        Transaction::create(['order_id' => $order->id, 'user_id' => $this->user->id, 'payment_method' => 'cod', 'status' => 'paid']);

        $this->assertEquals(1, Transaction::pending()->count());
        $this->assertEquals(1, Transaction::paid()->count());
    }

    // ========== Order Model: Payment Status for COD ==========

    /** @test */
    public function order_payment_status_returns_pending_for_cod_with_pending_transaction()
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'total_price' => 100,
            'payment_method' => 'cod',
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
        ]);

        $this->assertEquals('payment-pending', $order->payment_status);
    }

    /** @test */
    public function order_payment_status_returns_success_for_cod_with_paid_transaction()
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'total_price' => 100,
            'payment_method' => 'cod',
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'paid_at' => now(),
        ]);

        $this->assertEquals('payment-success', $order->payment_status);
    }

    // ========== Order Model: Pickup Location Relationship ==========

    /** @test */
    public function order_belongs_to_pickup_location()
    {
        $location = $this->createPickupLocation();

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test',
            'total_price' => 100,
            'pickup_location_id' => $location->id,
            'status' => 'pending',
        ]);

        $this->assertNotNull($order->pickupLocation);
        $this->assertEquals($location->id, $order->pickupLocation->id);
    }

    // ========== Order Model: Scopes ==========

    /** @test */
    public function order_delivery_and_pickup_scopes_work()
    {
        Order::create(['user_id' => $this->user->id, 'name' => 'D1', 'total_price' => 100, 'fulfillment_type' => 'delivery', 'status' => 'pending']);
        Order::create(['user_id' => $this->user->id, 'name' => 'D2', 'total_price' => 100, 'fulfillment_type' => 'delivery', 'status' => 'pending']);
        Order::create(['user_id' => $this->user->id, 'name' => 'P1', 'total_price' => 100, 'fulfillment_type' => 'pickup', 'status' => 'pending']);

        $this->assertEquals(2, Order::delivery()->count());
        $this->assertEquals(1, Order::pickup()->count());
    }

    // ========== Validation Tests ==========

    /** @test */
    public function checkout_requires_authentication()
    {
        $response = $this->postJson(self::PREFIX . '/general/checkout', []);

        $response->assertStatus(401);
    }

    /** @test */
    public function checkout_validates_required_fields()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson(self::PREFIX . '/general/checkout', []);

        $response->assertStatus(422);
    }

    /** @test */
    public function checkout_accepts_new_payment_fields_as_optional()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $response->assertStatus(200);
    }

    /** @test */
    public function checkout_rejects_invalid_payment_method()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'invalid_method',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function checkout_rejects_invalid_fulfillment_type()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'invalid',
        ]);

        $response->assertStatus(422);
    }

    // ========== OrderService: markCodAsPaid ==========

    /** @test */
    public function mark_cod_as_paid_updates_transaction_and_order()
    {
        Event::fake([\App\Events\PaymentSucceeded::class]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'COD Order',
            'total_price' => 100,
            'payment_method' => 'cod',
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->markCodAsPaid($order);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'cod',
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);
    }

    /** @test */
    public function mark_cod_as_paid_throws_exception_without_pending_transaction()
    {
        $this->expectException(\RuntimeException::class);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'COD Order',
            'total_price' => 100,
            'payment_method' => 'cod',
            'status' => 'pending',
        ]);

        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->markCodAsPaid($order);
    }

    // ========== OrderStatus Enum ==========

    /** @test */
    public function order_status_enum_has_ready_for_pickup()
    {
        $this->assertTrue(defined(\Marvel\Enums\OrderStatus::class . '::READY_FOR_PICKUP'));
        $this->assertEquals('order-ready-for-pickup', \Marvel\Enums\OrderStatus::READY_FOR_PICKUP);
    }

    // ========== Governorate Shipping Price ==========

    /** @test */
    public function checkout_with_delivery_and_governorate_stores_shipping_price()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals(1, $order->governorate_id);
        $this->assertEquals(50.00, $order->shipping_price);
        $this->assertEquals(150.00, $order->total_price);
    }

    /** @test */
    public function checkout_with_governorate_missing_shipping_price_defaults_to_zero()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        Governorate::create([
            'country_id' => 1,
            'name' => 'Alexandria',
            'status' => true,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 2,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals(2, $order->governorate_id);
        $this->assertEquals(0, $order->shipping_price);
    }

    /** @test */
    public function checkout_with_free_shipping_threshold_met_charges_zero_shipping()
    {
        Sanctum::actingAs($this->user);
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 600.00,
        ]);
        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => 6,
            'price' => 100.00,
            'total_price' => 600.00,
            'shipping_method' => 'SCHEDULED',
        ]);

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertEquals(0, $order->shipping_price);
    }

    /** @test */
    public function checkout_without_governorate_id_still_works_for_non_delivery()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();
        $this->createPickupLocation();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'pay_at_cashier',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => 1,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $order = Order::where('user_id', $this->user->id)->latest()->first();
        $this->assertNull($order->governorate_id);
    }

    /** @test */
    public function checkout_with_delivery_rejects_missing_governorate_id()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['governorate_id' => ['The governorate field is required.']]);
    }

    /** @test */
    public function checkout_with_invalid_governorate_id_is_rejected()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 999,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['governorate_id' => ['The selected governorate is invalid.']]);
    }
}
