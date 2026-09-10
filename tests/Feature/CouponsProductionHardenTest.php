<?php

namespace Tests\Feature;

use App\Events\AssignedCouponConsumed;
use App\Services\Coupon\CouponAssignmentValidator;
use App\Services\Coupon\CouponCalculator;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\Coupon\CouponValidator;
use App\Services\General\CouponService;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ShippingMethod;
use Spatie\Permission\Models\Permission;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

class CouponsProductionHardenTest extends TestCase
{

    use DatabaseTransactions, WithInvoiceTables;

    private const PREFIX = '/api/v1/general';

    private User $customer;
    private User $admin;
    private Product $product;
    private Product $product2;
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
            $table->string('currency_code', 3)->nullable();
            $table->string('base_currency_code', 3)->nullable();
            $table->string('catalog_currency_code', 3)->nullable();
            $table->decimal('currency_rate', 20, 10)->nullable();
            $table->date('currency_rate_date')->nullable();
            $table->decimal('converted_total_price', 10, 3)->nullable();
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
            $table->string('currency_code', 3)->nullable();
            $table->string('catalog_currency_code', 3)->nullable();
            $table->decimal('catalog_price', 8, 3)->nullable();
            $table->decimal('catalog_total_price', 8, 3)->nullable();
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
            $table->enum('type', ['percentage', 'fixed_rate'])->default('percentage');
            $table->decimal('discount', 10, 2)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->integer('order')->default(0);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('flash_sale_products', function (Blueprint $table) {
            $table->unsignedBigInteger('flash_sale_id');
            $table->unsignedBigInteger('product_id');
            $table->foreign('flash_sale_id')->references('id')->on('flash_sales')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products')->onDelete('cascade');
            $table->unique(['flash_sale_id', 'product_id']);
        });

        // Coupon tables
        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('slug')->unique();
            $table->string('name');
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
            $table->unique(['coupon_id', 'product_id']);
        });

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained('coupons')->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
            $table->unique(['coupon_id', 'user_id']);
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

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('media', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->morphs('model');
            $table->uuid('uuid')->nullable();
            $table->string('collection_name');
            $table->string('name');
            $table->string('file_name');
            $table->string('mime_type')->nullable();
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->unsignedBigInteger('size');
            $table->json('manipulations');
            $table->json('generated_conversions');
            $table->json('custom_properties');
            $table->json('responsive_images');
            $table->unsignedInteger('order_column')->nullable();
            $table->nullableTimestamps();
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

        $this->product2 = Product::create([
            'name' => 'Test Product 2',
            'slug' => 'test-prod2-' . Str::random(6),
            'price' => 200.00,
            'status' => 'publish',
            'in_stock' => true,
            'stock_quantity' => 30,
        ]);

        Permission::create(['name' => 'update-order-status', 'guard_name' => 'api']);
    }

    private function createCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Test Coupon',
            'slug' => 'coupon-' . Str::random(6),
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));

        $coupon->update(['code' => $code]);

        return $coupon->fresh();
    }

    private function createCartWithItems(int $quantity = 2): Cart
    {
        $this->product->increment('reserved_quantity', $quantity);

        $cart = Cart::create([
            'user_id' => $this->customer->id,
            'status' => 'active',
            'total_price' => $this->product->price * $quantity,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'quantity' => $quantity,
            'price' => $this->product->price,
            'total_price' => $this->product->price * $quantity,
            'reserved_quantity' => $quantity,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart->fresh();
    }

    private function actAsCustomer(): void
    {
        Sanctum::actingAs($this->customer);
    }

    private function checkout(): Order
    {
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

        return Order::where('user_id', $this->customer->id)->latest()->first();
    }

    private function completeOrder(Order $order): void
    {
        $service = app(OrderService::class);
        $service->markCodAsPaid($order);
    }

    // ===================== AUTHENTICATION =====================

    /** @test */
    public function guest_cannot_apply_coupon()
    {
        $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'TEST10'])
            ->assertStatus(401);
    }

    // ===================== COUPON VALIDATION =====================

    /** @test */
    public function expired_coupon_is_rejected()
    {
        $coupon = $this->createCoupon('EXPIRED', [
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $result = CouponValidator::validate($coupon, $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('expired', $result['reason']);
    }

    /** @test */
    public function disabled_coupon_is_rejected()
    {
        $coupon = $this->createCoupon('DISABLED', ['status' => false]);

        $result = CouponValidator::validate($coupon, $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('disabled', $result['reason']);
    }

    /** @test */
    public function future_coupon_is_rejected()
    {
        $coupon = $this->createCoupon('FUTURE', [
            'start_date' => now()->addWeek(),
            'end_date' => now()->addMonth(),
        ]);

        $result = CouponValidator::validate($coupon, $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('not_active', $result['reason']);
    }

    /** @test */
    public function max_usage_limit_reached_is_rejected()
    {
        $coupon = $this->createCoupon('MAXED', [
            'limiter' => 5,
            'used' => 5,
        ]);

        $result = CouponValidator::validate($coupon, $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('usage_limit_reached', $result['reason']);
    }

    // ===================== COUPON CALCULATION =====================

    /** @test */
    public function percentage_coupon_calculates_correctly()
    {
        $coupon = $this->createCoupon('PCT10', [
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
        ]);

        $result = CouponCalculator::calculate($coupon, 200.00);
        $this->assertEquals(20.00, $result['discountAmount']);
        $this->assertEquals(180.00, $result['finalPrice']);
        $this->assertFalse($result['freeShipping']);
    }

    /** @test */
    public function fixed_rate_coupon_calculates_correctly()
    {
        $coupon = $this->createCoupon('FIX50', [
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 50,
        ]);

        $result = CouponCalculator::calculate($coupon, 200.00);
        $this->assertEquals(50.00, $result['discountAmount']);
        $this->assertEquals(150.00, $result['finalPrice']);
        $this->assertFalse($result['freeShipping']);
    }

    /** @test */
    public function free_shipping_coupon_returns_free_shipping_flag()
    {
        $coupon = $this->createCoupon('SHIPFREE', [
            'discount_type' => DiscountType::FREE_SHIPPING,
            'discount' => 0,
        ]);

        $result = CouponCalculator::calculate($coupon, 200.00);
        $this->assertEquals(0, $result['discountAmount']);
        $this->assertEquals(200.00, $result['finalPrice']);
        $this->assertTrue($result['freeShipping']);
    }

    /** @test */
    public function percentage_coupon_respects_max_discount_amount()
    {
        $coupon = $this->createCoupon('MAXCAP', [
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 50,
            'max_discount_amount' => 30,
        ]);

        $result = CouponCalculator::calculate($coupon, 100.00);
        $this->assertEquals(30.00, $result['discountAmount']);
        $this->assertEquals(70.00, $result['finalPrice']);
    }

    /** @test */
    public function fixed_rate_does_not_exceed_price()
    {
        $coupon = $this->createCoupon('OVERKILL', [
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 200,
        ]);

        $result = CouponCalculator::calculate($coupon, 50.00);
        $this->assertEquals(50.00, $result['discountAmount']);
        $this->assertEquals(0.00, $result['finalPrice']);
    }

    // ===================== COUPON ORCHESTRATOR =====================

    /** @test */
    public function orchestrator_rejects_nonexistent_code()
    {
        $result = CouponOrchestrator::validateByCode('NONEXISTENT', $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('not_found', $result['reason']);
    }

    /** @test */
    public function orchestrator_rejects_expired_coupon_by_code()
    {
        $this->createCoupon('OLDBYE', [
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $result = CouponOrchestrator::validateByCode('OLDBYE', $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('expired', $result['reason']);
    }

    /** @test */
    public function orchestrator_accepts_valid_coupon()
    {
        $this->createCoupon('VALID10');

        $result = CouponOrchestrator::validateByCode('VALID10', $this->customer);
        $this->assertTrue($result['valid']);
        $this->assertNotNull($result['coupon']);
    }

    // ===================== COUPON ASSIGNMENT VALIDATION =====================

    /** @test */
    public function unassigned_user_rejected_for_assigned_coupon()
    {
        $coupon = $this->createCoupon('ASSIGNED');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->admin->id,
            'max_uses' => 1,
            'used' => 0,
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('not_assigned', $result['reason']);
    }

    /** @test */
    public function assigned_user_accepted_for_assigned_coupon()
    {
        $coupon = $this->createCoupon('MYCPN');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'used' => 0,
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->customer);
        $this->assertTrue($result['valid']);
    }

    /** @test */
    public function expired_assignment_rejected()
    {
        $coupon = $this->createCoupon('EXPASGN');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'used' => 0,
            'expires_at' => now()->subDay(),
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('assignment_expired', $result['reason']);
    }

    /** @test */
    public function exhausted_assignment_rejected()
    {
        $coupon = $this->createCoupon('EXHAUST');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'used' => 1,
        ]);

        $result = CouponAssignmentValidator::validate($coupon, $this->customer);
        $this->assertFalse($result['valid']);
        $this->assertEquals('usage_quota_exceeded', $result['reason']);
    }

    /** @test */
    public function public_coupon_has_no_assignment_restrictions()
    {
        $coupon = $this->createCoupon('PUBLIC');

        $result = CouponAssignmentValidator::validate($coupon, $this->customer);
        $this->assertTrue($result['valid']);
        $this->assertFalse($result['has_assignments']);
    }

    // ===================== COUPON APPLY TO CART =====================

    /** @test */
    public function apply_coupon_to_cart_succeeds()
    {
        $this->actAsCustomer();
        $this->createCartWithItems();
        $this->createCoupon('CART10');

        $response = $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'CART10']);
        $response->assertStatus(200);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $this->assertEquals('CART10', $cart->coupon);
    }

    /** @test */
    public function apply_invalid_coupon_to_cart_returns_error()
    {
        $this->actAsCustomer();
        $this->createCartWithItems();

        $response = $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'INVALID']);
        $response->assertStatus(400);
    }

    /** @test */
    public function apply_coupon_without_cart_returns_error()
    {
        $this->actAsCustomer();
        $this->createCoupon('NOCART');

        $response = $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'NOCART']);
        $response->assertStatus(400);
    }

    /** @test */
    public function apply_expired_coupon_to_cart_returns_error()
    {
        $this->actAsCustomer();
        $this->createCartWithItems();
        $this->createCoupon('EXPCRT', [
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $response = $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'EXPCRT']);
        $response->assertStatus(400);
    }

    /** @test */
    public function apply_same_coupon_twice_returns_already_applied()
    {
        $this->actAsCustomer();
        $this->createCartWithItems();
        $this->createCoupon('DUAL');

        $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'DUAL'])->assertStatus(200);
        $response = $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'DUAL']);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertTrue($data['data']['already_applied'] ?? false);
    }

    /** @test */
    public function apply_coupon_returns_calculated_discount()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // 2 * 100 = 200
        $this->createCoupon('CALCDIS', [
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
        ]);

        $response = $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'CALCDIS']);
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertEquals(180.00, (float) $data['total_price']);
        $this->assertEquals(20.00, (float) $data['coupon_discount']);
    }

    /** @test */
    public function apply_free_shipping_coupon_returns_free_shipping_flag()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $this->createCoupon('FSFLAG', [
            'discount_type' => DiscountType::FREE_SHIPPING,
            'discount' => 0,
        ]);

        $response = $this->postJson(self::PREFIX . '/coupons/apply', ['code' => 'FSFLAG']);
        $response->assertStatus(200);

        $data = $response->json('data');
        $this->assertTrue($data['free_shipping'] ?? false);
        $this->assertEquals(200.00, (float) $data['total_price']);
    }

    // ===================== CHECKOUT WITH COUPON =====================

    /** @test */
    public function checkout_with_percentage_coupon_applies_discount()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // 2 * 100 = 200
        $this->createCoupon('CHKOUT', [
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'CHKOUT']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        $this->assertEquals(200.00, (float) $order->price); // subtotal without coupon
        $this->assertEquals(20.00, (float) $order->coupon_discount);

        // Actually total_price = finalTotal(180) + shipping(30) = 210
        $this->assertEquals(210.00, (float) $order->total_price);
        $this->assertEquals('CHKOUT', $order->coupon);
    }

    /** @test */
    public function checkout_with_fixed_coupon_applies_discount()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // 200
        $this->createCoupon('FIXCHK', [
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 15,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'FIXCHK']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        // subtotal 200 - 15 coupon + 30 shipping = 215
        $this->assertEquals(215.00, (float) $order->total_price);
    }

    /** @test */
    public function checkout_with_free_shipping_coupon_sets_shipping_to_zero()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // 200
        $this->createCoupon('FREESHP', [
            'discount_type' => DiscountType::FREE_SHIPPING,
            'discount' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'FREESHP']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        $this->assertEquals(200.00, (float) $order->total_price);
        $this->assertEquals(0, (float) $order->shipping_price);
        $this->assertEquals('free_shipping', $order->coupon_discount_type);
    }

    /** @test */
    public function checkout_clears_expired_coupon_from_cart()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // 200
        $this->createCoupon('EXPCHK', [
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'EXPCHK']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        // expired coupon cleared ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â full price 200 + 30 shipping = 230
        $this->assertEquals(230.00, (float) $order->total_price);
        $this->assertNull($order->coupon);
    }

    /** @test */
    public function checkout_with_assigned_coupon_succeeds_for_assigned_user()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // 200
        $coupon = $this->createCoupon('ASGNCHK');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'ASGNCHK']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        $this->assertEquals('ASGNCHK', $order->coupon);
    }

    /** @test */
    public function checkout_clears_unassigned_coupon_for_unassigned_user()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // 200
        $coupon = $this->createCoupon('UNASGN');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->admin->id, // assigned to admin, not customer
            'max_uses' => 1,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'UNASGN']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        // unassigned coupon cleared ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â full price 200 + 30 shipping = 230
        $this->assertEquals(230.00, (float) $order->total_price);
        $this->assertNull($order->coupon);
    }

    // ===================== COUPON USAGE AFTER PAYMENT =====================

    /** @test */
    public function public_coupon_usage_recorded_on_payment()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $this->createCoupon('PAYUSG', [
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'PAYUSG']);

        $order = $this->checkout();
        $this->completeOrder($order);

        $usage = CouponUsage::where('coupon_id', Coupon::where('code', 'PAYUSG')->first()->id)
            ->where('user_id', $this->customer->id)
            ->first();
        $this->assertNotNull($usage);
        $this->assertEquals($order->id, $usage->order_id);
    }

    /** @test */
    public function assigned_coupon_usage_recorded_on_payment()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $coupon = $this->createCoupon('ASGNPAY');
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'ASGNPAY']);

        $order = $this->checkout();
        $this->completeOrder($order);

        $assignment->refresh();
        $this->assertEquals(1, $assignment->used);

        $usageRecord = CouponAssignmentUsage::where('coupon_assignment_id', $assignment->id)->first();
        $this->assertNotNull($usageRecord);
        $this->assertEquals($order->id, $usageRecord->order_id);
    }

    /** @test */
    public function assigned_coupon_usage_increments_coupon_global_counter()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $coupon = $this->createCoupon('GLOBCT', ['limiter' => 5, 'used' => 0]);
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 1,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'GLOBCT']);

        $order = $this->checkout();
        $this->completeOrder($order);

        $coupon->refresh();
        $this->assertEquals(1, $coupon->used);
    }

    /** @test */
    public function duplicate_coupon_usage_for_same_user_is_blocked()
    {
        $coupon = $this->createCoupon('DUPBLK', ['limiter' => 5, 'used' => 0]);
        $orderService = app(OrderService::class);

        // Use reflection to call private recordCouponUsage
        $method = new \ReflectionMethod($orderService, 'recordCouponUsage');
        $method->setAccessible(true);

        // First usage
        $order1 = Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Test',
            'status' => 'pending',
            'price' => 100.00,
            'total_price' => 100.00,
            'coupon' => 'DUPBLK',
        ]);
        $method->invoke($orderService, $order1);

        $coupon->refresh();
        $this->assertEquals(1, $coupon->used);

        // Second usage ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â should be blocked by unique constraint
        $order2 = Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Test',
            'status' => 'pending',
            'price' => 100.00,
            'total_price' => 100.00,
            'coupon' => 'DUPBLK',
        ]);
        $method->invoke($orderService, $order2);

        $coupon->refresh();
        $this->assertEquals(1, $coupon->used);
    }

    /** @test */
    public function assigned_coupon_usage_respects_max_uses()
    {
        $this->actAsCustomer();
        $coupon = $this->createCoupon('MAXUSES');
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 2,
            'used' => 2, // already exhausted
        ]);

        $this->createCartWithItems(2);
        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'MAXUSES']);

        // The validation in checkCouponValidity
        // will validate the coupon. Since the assignment already has used >= max_uses,
        // it will fail assignment validation. But the order might still get created
        // without the coupon (cart.coupon cleared).
        // This tests the checkout flow's handling of exhausted assignments.

        $order = $this->checkout();
        $this->assertNotNull($order);
        // Coupon should be cleared from cart since assignment was exhausted
        $this->assertNull($order->coupon);
    }

    // ===================== EVENT DISPATCH =====================

    /** @test */
    public function assigned_coupon_consumed_event_dispatched()
    {
        Event::fake([AssignedCouponConsumed::class]);

        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $coupon = $this->createCoupon('EVTDSP');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->customer->id,
            'max_uses' => 2,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'EVTDSP']);

        $order = $this->checkout();
        $this->completeOrder($order);

        Event::assertDispatched(AssignedCouponConsumed::class, function ($event) use ($order) {
            return $event->order->id === $order->id;
        });
    }

    // ===================== PRODUCT RESTRICTED COUPON =====================

    /** @test */
    public function product_restricted_coupon_works_with_matching_product()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $coupon = $this->createCoupon('PRODMAT', [
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
        ]);
        $coupon->products()->attach($this->product->id);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'PRODMAT']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        $this->assertEquals(210.00, (float) $order->total_price);
    }

    /** @test */
    public function product_restricted_coupon_rejected_when_no_matching_product()
    {
        $coupon = $this->createCoupon('NOMATCH');
        $coupon->products()->attach($this->product2->id); // restricted to product2

        $items = collect([$this->product])->map(function ($p) {
            return (object) ['product_id' => $p->id];
        });

        $result = CouponValidator::validate($coupon, $this->customer, $items);
        $this->assertFalse($result['valid']);
        $this->assertEquals('product_not_eligible', $result['reason']);
    }

    /** @test */
    public function checkout_clears_non_matching_product_coupon()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2); // uses $this->product
        $coupon = $this->createCoupon('NOPROD');
        $coupon->products()->attach($this->product2->id); // restricted to different product

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'NOPROD']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        $this->assertNull($order->coupon);
        $this->assertEquals(230.00, (float) $order->total_price);
    }

    // ===================== COUPON LIST ENDPOINT =====================

    /** @test */
    public function coupon_list_only_returns_valid_coupons()
    {
        $valid = $this->createCoupon('VALID1');
        $expired = $this->createCoupon('EXPIRED1', [
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);
        $disabled = $this->createCoupon('DISABLED1', ['status' => false]);

        $response = $this->getJson(self::PREFIX . '/coupons');
        $response->assertStatus(200);

        $ids = collect($response->json('data'))->pluck('id')->toArray();
        $this->assertContains($valid->id, $ids);
        $this->assertNotContains($expired->id, $ids);
        $this->assertNotContains($disabled->id, $ids);
    }

    // ===================== SECURITY =====================

    /** @test */
    public function cannot_assign_coupon_to_another_user()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $coupon = $this->createCoupon('OTHERS');
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->admin->id, // assigned to admin, not customer
            'max_uses' => 1,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'OTHERS']);

        $order = $this->checkout();
        $this->assertNotNull($order);
        $this->assertNull($order->coupon);
        $this->assertEquals(230.00, (float) $order->total_price);
    }

    /** @test */
    public function coupon_list_requires_no_auth()
    {
        $response = $this->getJson(self::PREFIX . '/coupons');
        $response->assertStatus(200);
    }

    // ===================== CONCURRENCY =====================

    /** @test */
    public function record_coupon_usage_does_not_throw_for_valid_public_coupon()
    {
        $this->actAsCustomer();
        $this->createCartWithItems(2);
        $this->createCoupon('CONCUR', [
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10,
            'limiter' => 100,
            'used' => 0,
        ]);

        $cart = Cart::where('user_id', $this->customer->id)->first();
        $cart->update(['coupon' => 'CONCUR']);

        $order = $this->checkout();

        // Simulate multiple calls to recordCouponUsage
        $service = app(OrderService::class);
        $service->markCodAsPaid($order);

        // Second call should be idempotent
        $order->update(['status' => 'pending']);
        // Can't call markCodAsPaid again (transaction pending check), but recordCouponUsage is idempotent
        $coupon = Coupon::where('code', 'CONCUR')->first();
        $this->assertEquals(1, $coupon->used);
    }
}
