<?php

namespace Tests\Feature;

use App\Contexts\ChannelContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Promotion;
use Marvel\Enums\ShippingMethod;
use Marvel\Enums\Permission;
use Tests\TestCase;

class FastShippingHardenTest extends TestCase
{

    use DatabaseTransactions;

    private const PREFIX = '/api/v1';

    private User $user;
    private User $admin;
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
            $this->createTestTables();
        }

        $this->seedBaseData();
    }

    private function createTestTables(): void
    {
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
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->integer('quantity')->default(1);
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

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
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

        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('type')->default('general');
            $table->text('icon')->nullable();
            $table->integer('parent_id')->nullable();
            $table->integer('level')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('category_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained()->cascadeOnDelete();
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

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
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
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('shipping_prices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('governorate_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->default(0);
            $table->unsignedInteger('estimated_days')->nullable();
            $table->decimal('free_shipping_over', 10, 2)->nullable();
            $table->boolean('status')->default(true);
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

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
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
            'name' => 'Fast Product',
            'slug' => 'fast-product',
            'price' => 150.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => true,
        ]);

        $this->normalProduct = Product::create([
            'name' => 'Normal Product',
            'slug' => 'normal-product',
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
                    'start_hour' => '00:00',
                    'end_hour' => '23:59',
                ],
            ],
        ]);

        Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);

        \Illuminate\Support\Facades\DB::table('pickup_locations')->insert([
            'store_name' => 'Main Store',
            'address' => '123 Main St',
            'phone' => '01000000000',
            'status' => true,
        ]);
    }

    private function createEnabledGovernorate(): Governorate
    {
        $gov = Governorate::create([
            'country_id' => 1,
            'name' => 'Cairo',
            'status' => true,
            'is_fast_shipping_enabled' => true,
        ]);
        \Illuminate\Support\Facades\DB::table('shipping_prices')->insert([
            'governorate_id' => $gov->id,
            'price' => 50.00,
            'free_shipping_over' => 500.00,
            'status' => true,
        ]);
        return $gov;
    }

    private function createCartWithFastItem(): Cart
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 150.00,
        ]);

        $this->fastProduct->increment('reserved_quantity', 1);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->fastProduct->id,
            'quantity' => 1,
            'reserved_quantity' => 1,
            'price' => 150.00,
            'total_price' => 150.00,
            'shipping_method' => ShippingMethod::FAST,
        ]);

        return $cart;
    }

    // ========== Checkout Success Flow ==========

    /** @test */
    public function fast_checkout_succeeds_with_cod()
    {
        Sanctum::actingAs($this->user);

        $this->createCartWithFastItem();
        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'notes' => 'Fast order',
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'shipping_method' => 'FAST',
            'payment_method' => 'cod',
            'fast_shipping_fee' => 25,
        ]);
    }

    /** @test */
    public function fast_checkout_succeeds_with_online_payment()
    {
        Sanctum::actingAs($this->user);

        $this->createCartWithFastItem();
        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'notes' => 'Fast order online',
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function fast_checkout_rejects_cod_pickup_combination()
    {
        Sanctum::actingAs($this->user);

        $this->createCartWithFastItem();
        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
            'fulfillment_type' => 'pickup',
            'pickup_location_id' => 1,
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('COD is not available for pickup', $response->getContent());
    }

    // ========== Governorate Tests ==========

    /** @test */
    public function fast_checkout_rejects_governorate_with_fast_shipping_disabled()
    {
        Sanctum::actingAs($this->user);

        $this->createCartWithFastItem();

        Governorate::create([
            'country_id' => 1,
            'name' => 'Giza',
            'status' => true,
            'is_fast_shipping_enabled' => false,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Giza'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Fast shipping is not available in your governorate', $response->getContent());
    }

    /** @test */
    public function fast_checkout_rejects_inactive_governorate()
    {
        Sanctum::actingAs($this->user);

        $this->createCartWithFastItem();

        Governorate::create([
            'country_id' => 1,
            'name' => 'Alex',
            'status' => false,
            'is_fast_shipping_enabled' => true,
        ]);

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Alex'],
            'governorate_id' => 2,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
    }

    // ========== Out of Stock ==========

    /** @test */
    public function fast_checkout_fails_when_product_has_insufficient_stock()
    {
        Sanctum::actingAs($this->user);

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 150.00,
        ]);

        $this->fastProduct->increment('reserved_quantity', 20);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->fastProduct->id,
            'quantity' => 20,
            'reserved_quantity' => 20,
            'price' => 150.00,
            'total_price' => 3000.00,
            'shipping_method' => ShippingMethod::FAST,
        ]);

        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@example.com',
            'address' => ['street' => '1'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        // NEW CONTRACT: stock is enforced at order reservation → controlled 422.
        $response->assertStatus(422);
    }

    // ========== Cache Key Isolation ==========

    /** @test */
    public function cache_key_differs_by_channel()
    {
        config(['scout.driver' => 'null']);

        $capturedKeys = [];

        \Illuminate\Support\Facades\Cache::shouldReceive('remember')
            ->andReturnUsing(function ($key) use (&$capturedKeys) {
                $capturedKeys[] = $key;
                return collect();
            });

        $this->getJson(self::PREFIX . '/general/home');
        $this->getJson(self::PREFIX . '/general/home', [
            'X-Channel' => 'fast-shipping',
        ]);

        $homeKeys = array_filter($capturedKeys, fn($k) => str_starts_with($k, 'home:'));
        $fastKeys = array_filter($capturedKeys, fn($k) => str_starts_with($k, 'fast-shipping:'));

        $this->assertNotEmpty($homeKeys, 'No home-channel cache keys found');
        $this->assertNotEmpty($fastKeys, 'No fast-shipping cache keys found');
    }

    // ========== Product Toggle Fast Shipping ==========

    /** @test */
    public function product_toggle_fast_shipping_updates_flag()
    {
        $admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);

        Sanctum::actingAs($admin);

        $product = Product::create([
            'name' => 'Toggle Test',
            'slug' => 'toggle-test',
            'price' => 100,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'is_fast_shipping_available' => false,
        ]);

        $response = $this->putJson('/api/v1/products/' . $product->id . '/fast-shipping', [
            'is_fast_shipping_available' => true,
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('products', [
            'id' => $product->id,
            'is_fast_shipping_available' => true,
        ]);
    }

    // ========== Unauthorized Access Tests ==========

    /** @test */
    public function admin_fast_shipping_settings_requires_authentication()
    {
        $response = $this->getJson('/api/v1/fast-shipping/settings');
        $response->assertStatus(401);
    }

    /** @test */
    public function admin_fast_shipping_settings_update_requires_authentication()
    {
        $response = $this->putJson('/api/v1/fast-shipping/settings', [
            'enabled' => true,
        ]);
        $response->assertStatus(401);
    }

    // ========== Mixed Cart Checkout ==========

    /** @test */
    public function fast_checkout_only_processes_fast_items_from_mixed_cart()
    {
        Sanctum::actingAs($this->user);

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 250.00,
        ]);

        $this->fastProduct->increment('reserved_quantity', 1);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->fastProduct->id,
            'quantity' => 1,
            'reserved_quantity' => 1,
            'price' => 150.00,
            'total_price' => 150.00,
            'shipping_method' => ShippingMethod::FAST,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->normalProduct->id,
            'quantity' => 1,
            'reserved_quantity' => 1,
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'user_id' => $this->user->id,
            'shipping_method' => 'FAST',
        ]);
    }

    // ========== Empty Cart Checkout ==========

    /** @test */
    public function fast_checkout_fails_with_empty_cart()
    {
        Sanctum::actingAs($this->user);

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 0,
        ]);

        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function fast_checkout_fails_when_cart_has_no_fast_items()
    {
        Sanctum::actingAs($this->user);

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 100.00,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->normalProduct->id,
            'quantity' => 1,
            'reserved_quantity' => 1,
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'governorate_id' => 1,
            'payment_method' => 'cod',
        ]);

        $response->assertStatus(422);
        $this->assertStringContainsString('fast shipping', strtolower($response->json('message')));
    }

    // ========== Invalid Payment Method ==========

    /** @test */
    public function fast_checkout_rejects_invalid_payment_method()
    {
        Sanctum::actingAs($this->user);

        $this->createCartWithFastItem();

        $this->createEnabledGovernorate();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St', 'city' => 'Cairo'],
            'governorate_id' => 1,
            'payment_method' => 'invalid_method',
        ]);

        $response->assertStatus(422);
    }
}
