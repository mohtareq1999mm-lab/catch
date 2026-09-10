<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Order;
use Marvel\Enums\FlashSaleType;
use App\Services\Checkout\OrderCreationService;
use Tests\TestCase;

class OrderCreationFlowTest extends TestCase
{

    use DatabaseTransactions;

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        Config::set('scout.driver', 'null');

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
            $table->boolean('discount_status')->nullable();
            $table->dateTime('start_date')->nullable();
            $table->dateTime('end_date')->nullable();
            $table->decimal('price_after_discount', 10, 2)->nullable();
            $table->decimal('price_after_flash_sale', 10, 2)->nullable();
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

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
            $table->timestamps();
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
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
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

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->date('start_date')->default(now());
            $table->date('end_date');
            $table->string('type')->default('percentage');
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->integer('order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('flash_sale_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('flash_sale_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('sale_price', 10, 2)->nullable();
            $table->string('sku')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->id();
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
    }

    private function createProduct(array $overrides = []): Product
    {
        return Product::create(array_merge([
            'name' => 'Test Product ' . Str::random(6),
            'slug' => 'test-product-' . Str::random(6),
            'description' => 'A test product',
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'product_type' => 'simple',
            'has_discount' => false,
            'has_flash_sale' => false,
        ], $overrides));
    }

    private function createCartWithItem(Product $product, int $quantity = 1, float $unitPrice = 100.00): Cart
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => $unitPrice * $quantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ]);

        $cart->load(['items', 'items.product', 'items.product.flash_sales' => fn($q) => $q->valid()]);
        return $cart;
    }

    private function createVariant(Product $product, array $overrides = []): ProductVariant
    {
        return ProductVariant::create(array_merge([
            'product_id' => $product->id,
            'price' => 130.00,
            'stock_quantity' => 10,
            'in_stock' => true,
            'sku' => 'VAR-' . Str::random(6),
        ], $overrides));
    }

    private function createCartWithVariantItem(Product $product, ProductVariant $variant, int $quantity = 1, float $unitPrice = 130.00): Cart
    {
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => $unitPrice * $quantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => $quantity,
            'price' => $unitPrice,
            'total_price' => $unitPrice * $quantity,
        ]);

        $cart->load(['items', 'items.product', 'items.productVariant', 'items.product.flash_sales' => fn($q) => $q->valid()]);
        return $cart;
    }

    private function createActiveFlashSale(Product $product, array $overrides = []): FlashSale
    {
        $flashSale = FlashSale::create(array_merge([
            'title' => 'Test Flash Sale',
            'slug' => 'test-flash-sale-' . Str::random(6),
            'status' => true,
            'start_date' => Carbon::yesterday()->format('Y-m-d'),
            'end_date' => Carbon::tomorrow()->format('Y-m-d'),
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
        ], $overrides));

        $flashSale->products()->attach($product->id);

        return $flashSale;
    }

    // ========== Flash Sale Price Tests ==========

    /** @test */
    public function order_item_stores_correct_flash_sale_price_for_percentage_type(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => true,
        ]);
        $this->createActiveFlashSale($product, [
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(80.00, $orderItem->product_flash_sale_price);
    }

    /** @test */
    public function order_item_stores_correct_flash_sale_price_for_fixed_rate_type(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => true,
        ]);
        $this->createActiveFlashSale($product, [
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 25,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(75.00, $orderItem->product_flash_sale_price);
    }

    /** @test */
    public function order_item_stores_correct_flash_sale_price_for_final_price_type(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => true,
        ]);
        $this->createActiveFlashSale($product, [
            'type' => FlashSaleType::FINAL_PRICE,
            'discount' => 39.99,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(39.99, $orderItem->product_flash_sale_price);
    }

    /** @test */
    public function order_item_flash_sale_price_is_null_when_product_has_no_flash_sale(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => false,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertNull($orderItem->product_flash_sale_price);
    }

    /** @test */
    public function order_item_flash_sale_price_is_null_when_product_has_flash_sale_but_none_active(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => true,
        ]);
        $this->createActiveFlashSale($product, [
            'start_date' => Carbon::yesterday()->subDays(10)->format('Y-m-d'),
            'end_date' => Carbon::yesterday()->subDays(5)->format('Y-m-d'),
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertNull($orderItem->product_flash_sale_price);
    }

    // ========== Discount Price Tests ==========

    /** @test */
    public function order_item_stores_correct_discount_price_for_percentage_type(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(80.00, $orderItem->product_discount_price);
    }

    /** @test */
    public function order_item_stores_correct_discount_price_for_fixed_rate_type(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => true,
            'discount_type' => 'fixed',
            'discount_amount' => 15,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertEquals(85.00, $orderItem->product_discount_price);
    }

    /** @test */
    public function order_item_discount_price_is_null_when_product_has_no_discount(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => false,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);
        $this->assertNull($orderItem->product_discount_price);
    }

    /** @test */
    public function order_item_discount_price_uses_computed_price_not_raw_amount(): void
    {
        $product = $this->createProduct([
            'price' => 200.00,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 50,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 200.00, 'total_price' => 200.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Should NOT be 50 (raw discount_amount) ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â should be 100 (computed: 200 - 50% = 100)
        $this->assertNotEquals(50.00, $orderItem->product_discount_price);
        $this->assertEquals(100.00, $orderItem->product_discount_price);
    }

    // ========== Variant Pricing Tests ==========

    /** @test */
    public function variant_flash_sale_price_uses_variant_price_not_product_price_for_percentage(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => true,
        ]);
        $variant = $this->createVariant($product, ['price' => 130.00]);
        $this->createActiveFlashSale($product, [
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 20,
        ]);

        $cart = $this->createCartWithVariantItem($product, $variant, 1, 104.00);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 104.00, 'total_price' => 104.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Variant price = 130, flash 20% off ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 130 - 26 = 104
        // Should NOT use product price 100 ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 100 - 20 = 80
        $this->assertNotEquals(80.00, $orderItem->product_flash_sale_price);
        $this->assertEquals(104.00, $orderItem->product_flash_sale_price);
    }

    /** @test */
    public function variant_flash_sale_price_uses_variant_price_for_fixed_rate(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => true,
        ]);
        $variant = $this->createVariant($product, ['price' => 130.00]);
        $this->createActiveFlashSale($product, [
            'type' => FlashSaleType::FIXED_RATE,
            'discount' => 25,
        ]);

        $cart = $this->createCartWithVariantItem($product, $variant, 1, 105.00);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 105.00, 'total_price' => 105.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Variant price = 130, fixed rate 25 off ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 130 - 25 = 105
        // Should NOT use product price 100 ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 100 - 25 = 75
        $this->assertNotEquals(75.00, $orderItem->product_flash_sale_price);
        $this->assertEquals(105.00, $orderItem->product_flash_sale_price);
    }

    /** @test */
    public function variant_flash_sale_price_uses_variant_price_for_final_price(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_flash_sale' => true,
        ]);
        $variant = $this->createVariant($product, ['price' => 130.00]);
        $this->createActiveFlashSale($product, [
            'type' => FlashSaleType::FINAL_PRICE,
            'discount' => 99.99,
        ]);

        $cart = $this->createCartWithVariantItem($product, $variant, 1, 99.99);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 99.99, 'total_price' => 99.99]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Variant price = 130, final price = 99.99
        // Should NOT use product price 100 ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 100 - 99.99 = 0.01
        $this->assertEquals(99.99, $orderItem->product_flash_sale_price);
    }

    /** @test */
    public function variant_discount_price_uses_variant_price_for_percentage(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
        ]);
        $variant = $this->createVariant($product, ['price' => 130.00]);

        $cart = $this->createCartWithVariantItem($product, $variant, 1, 104.00);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 104.00, 'total_price' => 104.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Variant price = 130, 20% off ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 130 - 26 = 104
        // Should NOT use product price 100 ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 100 - 20 = 80
        $this->assertNotEquals(80.00, $orderItem->product_discount_price);
        $this->assertEquals(104.00, $orderItem->product_discount_price);
    }

    /** @test */
    public function variant_discount_price_uses_variant_price_for_fixed(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => true,
            'discount_type' => 'fixed',
            'discount_amount' => 15,
        ]);
        $variant = $this->createVariant($product, ['price' => 130.00]);

        $cart = $this->createCartWithVariantItem($product, $variant, 1, 115.00);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 115.00, 'total_price' => 115.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Variant price = 130, $15 off ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 130 - 15 = 115
        // Should NOT use product price 100 ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 100 - 15 = 85
        $this->assertNotEquals(85.00, $orderItem->product_discount_price);
        $this->assertEquals(115.00, $orderItem->product_discount_price);
    }

    /** @test */
    public function product_without_variant_still_uses_product_price(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
            'has_flash_sale' => true,
        ]);
        $this->createActiveFlashSale($product, [
            'type' => FlashSaleType::PERCENTAGE,
            'discount' => 10,
        ]);

        $cart = $this->createCartWithItem($product);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 100.00, 'total_price' => 100.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Flash sale is active: product price = 100, 10% flash ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 90
        $this->assertEquals(90.00, $orderItem->product_flash_sale_price);
        // Flash sale overrides normal discount ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â discount price is null
        $this->assertNull($orderItem->product_discount_price);
    }

    /** @test */
    public function variant_without_price_falls_back_to_product_price(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
        ]);
        $variant = $this->createVariant($product, ['price' => null]);

        $cart = $this->createCartWithVariantItem($product, $variant, 1, 80.00);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 80.00, 'total_price' => 80.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // Variant has no price ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ falls back to product price = 100, 20% off ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬ÃƒÂ¢Ã¢â‚¬Å¾Ã‚Â¢ 80
        $this->assertEquals(80.00, $orderItem->product_discount_price);
    }

    /** @test */
    public function variant_product_price_remains_effective_unit_price(): void
    {
        $product = $this->createProduct([
            'price' => 100.00,
            'has_discount' => true,
            'discount_type' => 'percentage',
            'discount_amount' => 20,
        ]);
        $variant = $this->createVariant($product, ['price' => 130.00]);

        $cart = $this->createCartWithVariantItem($product, $variant, 1, 104.00);
        $order = Order::create(['user_id' => $this->user->id, 'price' => 104.00, 'total_price' => 104.00]);

        $service = app(OrderCreationService::class);
        $result = $service->createOrderItems($order, $cart);

        $this->assertTrue($result);
        $orderItem = $order->orderItems()->first();
        $this->assertNotNull($orderItem);

        // product_price is the effective unit price (back-calculated from cart total)
        // This must always be correct regardless of variant pricing audit fields
        $this->assertEquals(104.00, $orderItem->product_price);
    }
}
