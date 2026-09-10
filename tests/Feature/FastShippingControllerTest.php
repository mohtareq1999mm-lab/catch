<?php

namespace Tests\Feature;

use App\Contexts\ChannelContext;
use App\Services\General\MyfatoraService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
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
use Marvel\Enums\ShippingMethod;
use Marvel\Database\Repositories\FastShippingRepository;
use Tests\TestCase;

class FastShippingControllerTest extends TestCase
{

    use DatabaseTransactions;

    private const PREFIX = '/api/v1';
    private const CHANNEL_HEADER = 'X-Channel';

    private User $user;
    private Product $fastProduct;
    private Product $normalProduct;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        Config::set('scout.driver', 'null');
        Config::set('channel.enabled', true);
        Config::set('channel.strict', false);

        if (!Schema::hasTable('products')) {
            $this->createAllTables();
        }

        $this->seedBaseData();
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

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->text('comment')->nullable();
            $table->integer('rating')->default(0);
            $table->boolean('approved')->default(true);
            $table->timestamps();
            $table->softDeletes();
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
            $table->softDeletes();
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
            $table->softDeletes();
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
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->string('invoice_id')->nullable();
            $table->string('payment_method')->nullable();
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

        $this->fastProduct = Product::create([
            'name' => 'Fast Shipping Product',
            'slug' => 'fast-product',
            'description' => 'This product supports fast shipping',
            'price' => 150.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => true,
        ]);

        $this->normalProduct = Product::create([
            'name' => 'Normal Product',
            'slug' => 'normal-product',
            'description' => 'This is a regular product',
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => false,
        ]);

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
    }

    // ========== Status Endpoint ==========

    /** @test */
    public function status_returns_enabled_when_settings_configured()
    {
        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/status');

        $response->assertOk();
        $response->assertJsonStructure([
            'status', 'message', 'success', 'data' => [
                'enabled', 'available', 'duration_minutes', 'fee', 'opens_at', 'closes_at',
            ],
        ]);
        $response->assertJsonPath('data.enabled', true);
        $response->assertJsonPath('data.duration_minutes', 120);
        $response->assertJsonPath('data.fee', 25);
        $response->assertJsonPath('data.opens_at', '08:00');
        $response->assertJsonPath('data.closes_at', '22:00');
    }

    /** @test */
    public function status_returns_unavailable_without_settings()
    {
        Settings::truncate();

        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/status');

        $response->assertOk();
        $response->assertJsonPath('data.enabled', false);
        $response->assertJsonPath('data.available', false);
    }

    /** @test */
    public function status_works_without_channel_header()
    {
        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/status', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.enabled', true);
    }

    // ========== Fast Shipping Products Endpoint ==========

    /** @test */
    public function fast_shipping_products_returns_only_fast_eligible_products()
    {
        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/products');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $productIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($this->fastProduct->id, $productIds);
        $this->assertNotContains($this->normalProduct->id, $productIds);
        $this->assertCount(1, $response->json('data.data'));
    }

    /** @test */
    public function fast_shipping_products_accepts_search_term()
    {
        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/products?search=Fast');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $name = $response->json('data.data.0.name');
        $this->assertNotNull($name);
    }

    /** @test */
    public function fast_shipping_products_returns_empty_for_non_matching_search()
    {
        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/products?search=Nonexistent');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    /** @test */
    public function fast_shipping_products_respects_limit_parameter()
    {
        Product::create([
            'name' => 'Another Fast Product',
            'slug' => 'another-fast-product',
            'price' => 200.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 5,
            'is_fast_shipping_available' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/products?limit=1');

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
    }

    /** @test */
    public function fast_shipping_products_response_has_pagination_meta()
    {
        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/products');

        $response->assertOk();
        $this->assertArrayHasKey('current_page', $response->json('data'));
        $this->assertArrayHasKey('last_page', $response->json('data'));
        $this->assertArrayHasKey('per_page', $response->json('data'));
        $this->assertArrayHasKey('total', $response->json('data'));
    }

    // ========== Fast Shipping Orders Endpoint ==========

    /** @test */
    public function fast_shipping_orders_requires_authentication()
    {
        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/orders');

        $response->assertStatus(401);
    }

    /** @test */
    public function fast_shipping_orders_returns_only_fast_orders_for_auth_user()
    {
        Sanctum::actingAs($this->user);

        $fastOrder = Order::create([
            'user_id' => $this->user->id,
            'shipping_method' => 'FAST',
            'price' => 150,
            'total_price' => 175,
            'fast_shipping_fee' => 25,
            'expected_delivery_at' => now()->addMinutes(120),
            'status' => 'pending',
        ]);

        Order::create([
            'user_id' => $this->user->id,
            'shipping_method' => 'SCHEDULED',
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
        ]);

        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/orders');

        $response->assertOk();
        $orderIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($fastOrder->id, $orderIds);
        $this->assertCount(1, $response->json('data.data'));
    }

    /** @test */
    public function fast_shipping_order_shows_eta_and_fee_fields()
    {
        Sanctum::actingAs($this->user);

        Order::create([
            'user_id' => $this->user->id,
            'shipping_method' => 'FAST',
            'price' => 150,
            'total_price' => 175,
            'fast_shipping_fee' => 25,
            'expected_delivery_at' => now()->addMinutes(120),
            'status' => 'pending',
        ]);

        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/orders');

        $response->assertOk();
        $order = $response->json('data.data.0');
        $this->assertEquals('FAST', $order['shipping_method']);
        $this->assertEquals(25, (float) $order['fast_shipping_fee']);
        $this->assertNotNull($order['expected_delivery_at']);
    }

    /** @test */
    public function fast_shipping_orders_returns_empty_for_user_without_orders()
    {
        Sanctum::actingAs($this->user);

        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/orders');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    /** @test */
    public function fast_shipping_orders_does_not_show_other_users_orders()
    {
        Sanctum::actingAs($this->user);

        $otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'password' => bcrypt('password'),
        ]);

        Order::create([
            'user_id' => $otherUser->id,
            'shipping_method' => 'FAST',
            'price' => 150,
            'total_price' => 175,
            'status' => 'pending',
        ]);

        $response = $this->getJson(self::PREFIX . '/general/fast-shipping/orders');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    // ========== Checkout Endpoint (Error Cases) ==========

    /** @test */
    public function fast_checkout_fails_without_active_cart()
    {
        Sanctum::actingAs($this->user);

        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);
        Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
            'is_fast_shipping_enabled' => true,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'notes' => 'Test order',
            'governorate_id' => 1,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Cart not found');
    }

    /** @test */
    public function fast_checkout_fails_when_validation_fails()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', []);

        $response->assertStatus(422);
    }

    // ========== Product Listing With Channel Header ==========

    /** @test */
    public function products_endpoint_excludes_fast_shipping_in_home_channel()
    {
        $response = $this->getJson(self::PREFIX . '/general/products');

        $response->assertOk();
        $productIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertContains($this->normalProduct->id, $productIds);
        $this->assertNotContains($this->fastProduct->id, $productIds);
    }

    /** @test */
    public function products_endpoint_filters_in_fast_shipping_channel()
    {
        $response = $this->getJson(self::PREFIX . '/general/products', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $productIds = collect($response->json('data.data'))->pluck('id')->toArray();
        $this->assertNotContains($this->normalProduct->id, $productIds);
        $this->assertContains($this->fastProduct->id, $productIds);
    }

    /** @test */
    public function product_detail_returns_404_for_non_fast_product_in_fast_channel()
    {
        $response = $this->getJson(self::PREFIX . '/general/products/normal-product', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertStatus(404);
    }

    /** @test */
    public function product_detail_shows_fast_product_in_fast_channel()
    {
        $response = $this->getJson(self::PREFIX . '/general/products/fast-product', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $this->assertEquals('Fast Shipping Product', $response->json('data.name'));
    }

    /** @test */
    public function product_detail_returns_normal_product_in_home_channel()
    {
        $response = $this->getJson(self::PREFIX . '/general/products/normal-product');

        $response->assertOk();
        $this->assertEquals('Normal Product', $response->json('data.name'));
    }

    /** @test */
    public function product_detail_returns_404_for_fast_product_in_home_channel()
    {
        $response = $this->getJson(self::PREFIX . '/general/products/fast-product');

        $response->assertStatus(404);
    }

    /** @test */
    public function product_detail_returns_404_for_nonexistent_slug()
    {
        $response = $this->getJson(self::PREFIX . '/general/products/nonexistent-product');

        $response->assertStatus(404);
    }

    /** @test */
    public function product_response_contains_required_fields()
    {
        $response = $this->getJson(self::PREFIX . '/general/products/fast-product', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $product = $response->json('data');
        $this->assertArrayHasKey('id', $product);
        $this->assertArrayHasKey('name', $product);
        $this->assertArrayHasKey('slug', $product);
        $this->assertArrayHasKey('price', $product);
    }

    /** @test */
    public function products_list_response_has_pagination_meta()
    {
        $response = $this->getJson(self::PREFIX . '/general/products');

        $response->assertOk();
        $payload = $response->json('data');
        $this->assertNotNull($payload, 'Response data is null: ' . $response->content());
        $this->assertArrayHasKey('data', $payload);
    }

    // ========== Categories With Channel Header ==========

    /** @test */
    public function categories_endpoint_works_in_both_channels()
    {
        $category = Category::create([
            'name' => ['en' => 'Test Category'],
        ]);
        $category->products()->attach([$this->fastProduct->id, $this->normalProduct->id]);

        $homeResponse = $this->getJson(self::PREFIX . '/general/categories');
        $homeResponse->assertOk();
        $homeData = $homeResponse->json('data');
        $homeIds = collect($homeData)->pluck('id')->toArray();
        $this->assertContains($category->id, $homeIds);

        $fastResponse = $this->getJson(self::PREFIX . '/general/categories', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);
        $fastResponse->assertOk();
        $fastIds = collect($fastResponse->json('data'))->pluck('id')->toArray();
        $this->assertContains($category->id, $fastIds);
    }

    /** @test */
    public function category_by_slug_returns_404_for_nonexistent()
    {
        $response = $this->getJson(self::PREFIX . '/general/categories/nonexistent-category');

        $response->assertStatus(404);
    }

    // ========== Banners, Sliders, Flash Sales, Brands With Channel Header ==========

    /** @test */
    public function banners_endpoint_works_with_channel_header()
    {
        Banner::create([
            'title' => 'Test Banner',
            'slug' => 'test-banner',
        ]);

        $response = $this->getJson(self::PREFIX . '/general/banners', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function sliders_endpoint_works_with_channel_header()
    {
        Slider::create([
            'title' => 'Test Slider',
            'slug' => 'test-slider',
        ]);

        $response = $this->getJson(self::PREFIX . '/general/sliders', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function flash_sales_endpoint_works_with_channel_header()
    {
        FlashSale::create([
            'title' => 'Test Flash Sale',
            'slug' => 'test-flash-sale',
            'status' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/general/flash-sales', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data'));
    }

    /** @test */
    public function brands_endpoint_works_with_channel_header()
    {
        Brand::create([
            'name' => 'Test Brand',
            'slug' => 'test-brand',
        ]);

        $response = $this->getJson(self::PREFIX . '/general/brands', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }

    /** @test */
    public function coupons_endpoint_works_with_channel_header()
    {
        Coupon::create([
            'code' => 'TEST10',
            'slug' => 'test10',
            'type' => 'general',
            'status' => true,
        ]);

        $response = $this->getJson(self::PREFIX . '/general/coupons', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $this->assertNotNull($response->json('data'));
    }

    // ========== Search Endpoint ==========

    /** @test */
    public function search_endpoint_works_with_channel_header()
    {
        $response = $this->getJson(self::PREFIX . '/general/search', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
    }

    // ========== Home Endpoint ==========

    /** @test */
    public function home_endpoint_returns_sections()
    {
        $response = $this->getJson(self::PREFIX . '/general/home');

        $response->assertOk();
    }

    // ========== Channel Header Behavior ==========

    /** @test */
    public function missing_channel_header_defaults_to_home()
    {
        $context = app(ChannelContext::class);

        $this->getJson(self::PREFIX . '/general/products?limit=1');

        $this->assertTrue($context->isHome());
    }

    /** @test */
    public function invalid_channel_header_falls_back_to_home_in_non_strict_mode()
    {
        Config::set('channel.strict', false);
        $context = app(ChannelContext::class);

        $this->getJson(self::PREFIX . '/general/products?limit=1', [
            self::CHANNEL_HEADER => 'invalid-channel',
        ]);

        $this->assertTrue($context->isHome());
    }

    /** @test */
    public function invalid_channel_header_returns_400_in_strict_mode()
    {
        Config::set('channel.strict', true);

        $response = $this->getJson(self::PREFIX . '/general/products?limit=1', [
            self::CHANNEL_HEADER => 'invalid-channel',
        ]);

        $response->assertStatus(400);
    }

    /** @test */
    public function empty_channel_header_defaults_to_home()
    {
        $context = app(ChannelContext::class);

        $this->getJson(self::PREFIX . '/general/products?limit=1', [
            self::CHANNEL_HEADER => '',
        ]);

        $this->assertTrue($context->isHome());
    }

    /** @test */
    public function channel_header_is_case_insensitive()
    {
        $response = $this->getJson(self::PREFIX . '/general/products', [
            self::CHANNEL_HEADER => 'FAST-SHIPPING',
        ]);

        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('Fast Shipping Product', $response->json('data.data.0.name'));
    }

    /** @test */
    public function channel_disabled_config_disables_scope()
    {
        Config::set('channel.enabled', false);

        $response = $this->getJson(self::PREFIX . '/general/products', [
            self::CHANNEL_HEADER => 'fast-shipping',
        ]);

        $response->assertOk();
        $this->assertCount(2, $response->json('data.data'));
    }

    // ========== Checkout translation test ==========

    /** @test */
    public function checkout_without_cart_returns_cart_not_found_translated()
    {
        Sanctum::actingAs($this->user);

        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);
        Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
            'is_fast_shipping_enabled' => true,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test'],
            'governorate_id' => 1,
        ]);

        $response->assertStatus(400);
        $this->assertEquals('Cart not found', $response->json('message'));
    }

    // ========== Translation tests ==========

    /** @test */
    public function cod_pickup_error_is_translated()
    {
        Sanctum::actingAs($this->user);

        $this->assertEquals(
            'COD is not available for pickup. Use pay_at_cashier instead.',
            __('message.ERROR.COD_NOT_AVAILABLE_FOR_PICKUP')
        );
    }
}
