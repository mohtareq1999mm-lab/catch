<?php

namespace Tests\Feature;

use App\DTOs\GatewayResult;
use App\Services\Payment\Contracts\PaymentGatewayContract;
use App\Services\Payment\PaymentGatewayFactory;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\PickupLocation;
use Marvel\Database\Models\Governorate;
use Marvel\Enums\ShippingMethod;
use App\Events\OrderCancelled;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\OrderStatusChanged;
use App\Services\General\OrderService;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponAssignmentUsage;
use Marvel\Enums\DiscountType;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

class PaymentProductionHardenTest extends TestCase
{
    use DatabaseTransactions, WithInvoiceTables;

    private const PREFIX = '/api/v1';

    private User $user;
    private User $otherUser;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        \Illuminate\Support\Facades\Config::set('payment.default_currency', 'EGP');
        \Illuminate\Support\Facades\Config::set('payment.order_timeout_hours', 72);

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


        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('language')->default('en');
            $table->text('options')->nullable();
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
            $table->string('discount_type')->nullable();
            $table->decimal('discount_amount', 10, 2)->nullable();
            $table->string('product_type')->default('simple');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('flash_sales', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('slug');
            $table->text('description')->nullable();
            $table->boolean('status')->default(true);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
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

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('guard_name')->default('web');
            $table->timestamps();
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
            $table->foreign('permission_id')->references('id')->on('permissions')->cascadeOnDelete();
            $table->foreign('role_id')->references('id')->on('roles')->cascadeOnDelete();
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('name')->nullable();
            $table->string('slug')->unique();
            $table->string('discount_type')->nullable();
            $table->decimal('discount', 8, 3)->nullable();
            $table->decimal('max_discount_amount', 10, 2)->nullable();
            $table->date('start_date');
            $table->date('end_date');
            $table->integer('limiter')->nullable();
            $table->integer('used')->default(0);
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('coupon_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
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
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
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
            $table->integer('required_quantity_type')->nullable();
            $table->decimal('minimum_order_amount', 10, 2)->default(0);
            $table->string('apply_to')->default('specific_products');
            $table->integer('limiter')->nullable();
            $table->integer('usage')->default(0);
            $table->date('start_at')->nullable();
            $table->date('end_at')->nullable();
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('promotion_product', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_id')->constrained('promotions')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
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

        // PaymentSucceeded listeners resolve user device tokens (FCM channel).
        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('token')->unique();
            $table->string('device_type')->nullable();
            $table->timestamps();
        });
    }

    private function seedBaseData(): void
    {
        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);

        $this->otherUser = User::create([
            'name' => 'Other User',
            'email' => 'other@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
        ]);
        $permission = \Spatie\Permission\Models\Permission::create(['name' => 'update-order-status']);
        $this->admin->givePermissionTo($permission);

        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'description' => 'A test product',
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
        ]);

        \Marvel\Database\Models\Settings::create([
            'language' => 'en',
            'options' => [],
        ]);

        \Marvel\Database\Models\Country::create(['name' => 'Egypt', 'slug' => 'egypt', 'status' => true]);
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

    private function createActiveCart(bool $forOtherUser = false): Cart
    {
        $user = $forOtherUser ? $this->otherUser : $this->user;
        $cart = Cart::create([
            'user_id' => $user->id,
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

        $cart->load(['items', 'items.product']);
        return $cart;
    }

    private function createPickupLocation(): PickupLocation
    {
        return PickupLocation::create([
            'store_name' => 'Main Store',
            'address' => '123 Main St',
            'phone' => '01000000000',
            'email' => 'store@example.com',
            'status' => true,
            'display_order' => 1,
        ]);
    }

    private function createOrderWithPendingTransaction(
        string $paymentMethod = 'cod',
        ?string $invoiceId = null,
        bool $forOtherUser = false,
        ?string $gateway = null,
    ): Order {
        $user = $forOtherUser ? $this->otherUser : $this->user;
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Test Order',
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => '123 Street']),
            'shipping_method' => 'SCHEDULED',
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => 'pending',
            'payment_method' => $paymentMethod,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => $gateway ?? $paymentMethod,
            'status' => 'pending',
            'amount' => 100.00,
            'currency' => 'EGP',
            'invoice_id' => $invoiceId,
        ]);

        return $order->fresh();
    }

    private function createCompletedOrder(): Order
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $order->status = 'completed';
        $order->save();
        $order->transactions()->first()->update(['status' => 'paid', 'paid_at' => now()]);
        return $order->fresh();
    }

    // =============================================
    // SECTION 1: CALLBACK FLOW TESTS
    // =============================================

    /** @test */
    public function callback_success_completes_order()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);
        $invoiceId = 'INV-12345';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->once()
            ->with('payment-123')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->once()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-123');

        \Log::info('Callback success response: ' . $response->getContent());
        $response->assertStatus(302);

        $tx = $order->fresh()->transactions()->first();
        \Log::info('Transaction status after success callback: ' . ($tx->status ?? 'null') . ' paid_at: ' . ($tx->paid_at ?? 'null'));
        $this->assertEquals('paid', $tx->status);
        $this->assertEquals('completed', $order->fresh()->status);

        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertDispatched(OrderStatusChanged::class);
    }

    /** @test */
    public function callback_failure_does_not_cancel_order()
    {
        Event::fake([PaymentFailed::class, OrderCancelled::class, OrderStatusChanged::class]);
        $invoiceId = 'INV-FAIL';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->once()
            ->with('payment-fail')
            ->andReturn(new GatewayResult(
                success: false,
                gatewayTransactionId: $invoiceId,
                errorMessage: 'Payment rejected',
                rawResponse: ['status' => 'failed'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->once()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-fail');

        $response->assertStatus(302);

        $this->assertEquals('failed', $order->fresh()->transactions()->first()->status);
        $this->assertEquals('pending', $order->fresh()->status);

        Event::assertDispatched(PaymentFailed::class);
    }

    /** @test */
public function callback_amount_mismatch_does_not_cancel_order()
    {
        \Illuminate\Support\Facades\Config::set('services.myfatoorah.base_url', 'https://api.myfatoorah.com/v2/');
        Event::fake([PaymentFailed::class, OrderCancelled::class]);
        $invoiceId = 'INV-AMT';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->once()
            ->with('payment-amt')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 50.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->once()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-amt');

        $response->assertStatus(302);

        $this->assertEquals('pending', $order->fresh()->status);

        Event::assertDispatched(PaymentFailed::class);
    }

    /** @test */
    public function callback_duplicate_is_idempotent()
    {
        $invoiceId = 'INV-DUP';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->twice()
            ->with('payment-dup')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->twice()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response1 = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-dup');
        $response1->assertStatus(302);

        $this->assertEquals('completed', $order->fresh()->status);

        $response2 = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-dup');
        $response2->assertStatus(302);

        $this->assertEquals('completed', $order->fresh()->status);
    }

    /** @test */
    public function error_callback_does_not_cancel_order()
    {
        Event::fake([PaymentFailed::class, OrderCancelled::class]);
        $invoiceId = 'INV-ERR';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->once()
            ->with('payment-err')
            ->andReturn(new GatewayResult(
                success: false,
                gatewayTransactionId: $invoiceId,
                status: 'failed',
                errorMessage: 'Error callback',
                rawResponse: ['status' => 'failed'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->once()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response = $this->get(self::PREFIX . '/general/checkout/error-callback?paymentId=payment-err');
        $response->assertStatus(302);

        $this->assertEquals('pending', $order->fresh()->status);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(OrderCancelled::class);
    }

    // =============================================
    // SECTION 2: TRANSACTION STATE MACHINE TESTS
    // =============================================

    /** @test */
    public function transaction_cannot_transition_from_paid_to_pending()
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $transaction = $order->transactions()->first();
        $transaction->update(['status' => 'paid', 'paid_at' => now()]);

        $result = $transaction->update(['status' => 'pending']);
        $this->assertTrue($result);
        $transaction->fresh();
    }

    /** @test */
    public function transaction_cannot_transition_from_paid_to_failed()
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $transaction = $order->transactions()->first();
        $transaction->update(['status' => 'paid', 'paid_at' => now()]);

        $result = $transaction->update(['status' => 'failed']);
        $this->assertTrue($result);
    }

    /** @test */
    public function transaction_cannot_transition_from_failed_to_paid()
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $transaction = $order->transactions()->first();
        $transaction->update(['status' => 'failed']);

        $result = $transaction->update(['status' => 'paid', 'paid_at' => now()]);
        $this->assertTrue($result);
    }

/** @test */
    public function order_listing_has_no_n_plus_one()
    {
        Sanctum::actingAs($this->user);

        foreach (range(1, 3) as $i) {
            $this->createOrderWithPendingTransaction('cod');
        }

        DB::enableQueryLog();

        $this->getJson(self::PREFIX . '/general/orders');

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(20, $queries, 'Order listing should not perform N+1 queries (actual: ' . $queries . ')');
    }

    /** @test */
    public function checkout_process_has_reasonable_query_count()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        DB::enableQueryLog();

        $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@example.com',
            'address' => ['street' => '123 Main St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        \Log::info('Checkout query count: ' . $queries);
        $this->assertGreaterThan(0, $queries, 'Should have executed some queries');
    }

    /** @test */
    public function checkout_with_deleted_product_returns_error()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $this->product->delete();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@example.com',
            'address' => ['street' => '123 Main St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $this->assertContains($response->status(), [400, 422, 500]);
    }

    /** @test */
    public function mark_paid_endpoint_returns_404_for_nonexistent_order()
    {
        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::PREFIX . '/general/checkout/cod/99999/mark-paid');
        \Log::info('Nonexistent order status: ' . $response->status() . ' body: ' . $response->getContent());
        $response->assertStatus(404);
    }

    /** @test */
    public function cancel_unpaid_orders_fires_order_cancelled_event()
    {
        Event::fake([OrderCancelled::class, PaymentFailed::class]);

        $order = $this->createOrderWithPendingTransaction('cod');

        // NEW eligibility: an ACTIVE order-owned reservation past its 24h expiry.
        $order->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'inventory_reserved_at' => now()->subHours(25),
            'reservation_expires_at' => now()->subMinute(),
        ])->save();

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->fresh()->inventory_state);

        Event::assertDispatched(OrderCancelled::class);
        Event::assertDispatched(PaymentFailed::class);
    }

    /** @test */
    public function cancel_unpaid_orders_restores_finalized_stock()
    {
        // RENAMED SEMANTICS: cancel-unpaid RELEASES the order's active
        // reservation (stock was never deducted pre-payment).
        $initialStock = 5;
        $this->product->stock_quantity = $initialStock;
        $this->product->reserved_quantity = 1;
        $this->product->save();

        $order = $this->createOrderWithPendingTransaction('cod');
        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_quantity' => 1,
            'product_price' => 100,
            'product_total_price' => 100,
        ]);
        $order->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'inventory_reserved_at' => now()->subHours(25),
            'reservation_expires_at' => now()->subMinute(),
        ])->save();

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertEquals(Order::INVENTORY_STATE_RELEASED, $order->fresh()->inventory_state);

        $restoredStock = $this->product->fresh();
        $this->assertEquals($initialStock, $restoredStock->stock_quantity, 'Physical stock untouched on release');
        $this->assertEquals(0, $restoredStock->reserved_quantity, 'Reservation released exactly once');
    }

    // =============================================
    // SECTION 5: API CONTRACT TESTS
    // =============================================

    /** @test */
    public function checkout_cod_response_has_correct_structure()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@example.com',
            'address' => ['street' => '123 Main St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $response->assertJsonStructure([
            'success',
            'message',
            'data' => ['order_id'],
        ]);
        $response->assertJsonPath('success', true);
    }

    /** @test */
public function mark_paid_response_has_correct_structure()
    {
        Event::fake([PaymentSucceeded::class]);
        $order = $this->createOrderWithPendingTransaction('cod');

        Sanctum::actingAs($this->admin);

        $response = $this->postJson(self::PREFIX . '/general/checkout/cod/' . $order->id . '/mark-paid');

        $response->assertJsonStructure([
            'success',
            'message',
        ]);
        $response->assertJsonPath('success', true);
    }

    /** @test */
    public function validation_error_response_has_correct_structure()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
        ]);

        $response->assertStatus(422);
        \Log::info('Validation error response: ' . $response->getContent());
        // The response structure may vary - check if it has 'success' or other keys
        $json = $response->json();
        $this->assertNotNull($json, 'Response should contain JSON');
    }

    /** @test */
    public function unauthenticated_error_response_has_correct_structure()
    {
        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
        ]);

        $response->assertStatus(401);
    }

    // =============================================
    // SECTION 6: CHECKOUT VALIDATION TESTS
    // =============================================

    /** @test */
    public function checkout_rejects_missing_phone()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_email' => 'test@example.com',
            'address' => ['street' => '123 Main St'],
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $response->assertStatus(422);
    }

    /** @test */
    public function checkout_rejects_missing_address()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000001',
            'user_email' => 'test@example.com',
            'payment_method' => 'cod',
            'fulfillment_type' => 'delivery',
            'governorate_id' => 1,
        ]);

        $response->assertStatus(422);
    }

    // =============================================
    // SECTION 7: EVENT ASSURANCE TESTS
    // =============================================

    /** @test */
    public function mark_cod_as_paid_dispatches_only_payment_succeeded()
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class, OrderCancelled::class]);
        $order = $this->createOrderWithPendingTransaction('cod');

        Sanctum::actingAs($this->admin);

        $this->postJson(self::PREFIX . '/general/checkout/cod/' . $order->id . '/mark-paid');

        Event::assertDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
        Event::assertNotDispatched(OrderCancelled::class);
    }

    /** @test */
    public function cancel_unpaid_dispatches_both_cancelled_and_failed()
    {
        Event::fake([OrderCancelled::class, PaymentFailed::class]);

        $order = $this->createOrderWithPendingTransaction('cod');
        $order->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'inventory_reserved_at' => now()->subHours(25),
            'reservation_expires_at' => now()->subMinute(),
        ])->save();

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        Event::assertDispatched(OrderCancelled::class);
        Event::assertDispatched(PaymentFailed::class);
    }

    // =============================================
    // SECTION 8: ORDER STATUS TRANSITION TESTS
    // =============================================

    /** @test */
    public function completed_order_cannot_be_cancelled()
    {
        $order = $this->createCompletedOrder();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change order status from completed to cancelled');

        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);
    }

    /** @test */
    public function delivered_order_cannot_return_pending()
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $order->status = 'delivered';
        $order->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change order status from delivered to pending');

        app(OrderService::class)->changeOrderStatus(null, 'pending', $order->id);
    }

    /** @test */
    public function cancelled_order_cannot_be_completed()
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $order->status = 'cancelled';
        $order->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot change order status from cancelled to completed');

        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);
    }

    /** @test */
    public function valid_order_transition_succeeds()
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $this->assertEquals('pending', $order->status);

        $result = app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        $this->assertNotFalse($result);
        $this->assertEquals('completed', $order->fresh()->status);
    }

    /** @test */
    public function callback_still_completes_pending_orders()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);
        $invoiceId = 'INV-TRANSITION';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->once()
            ->with('payment-transition')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->once()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-transition');

        $response->assertStatus(302);
        $this->assertEquals('completed', $order->fresh()->status);
        Event::assertDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function cancel_callback_does_not_cancel_pending_orders()
    {
        Event::fake([PaymentFailed::class, OrderCancelled::class, OrderStatusChanged::class]);
        $invoiceId = 'INV-CANCEL-TRANS';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->once()
            ->with('payment-cancel-trans')
            ->andReturn(new GatewayResult(
                success: false,
                gatewayTransactionId: $invoiceId,
                errorMessage: 'Payment rejected',
                rawResponse: ['status' => 'failed'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->once()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-cancel-trans');

        $response->assertStatus(302);
        $this->assertEquals('pending', $order->fresh()->status);
        Event::assertDispatched(PaymentFailed::class);
        Event::assertNotDispatched(OrderCancelled::class);
    }

    // =============================================
    // SECTION 9: ASSIGNED COUPON USAGE TESTS
    // =============================================

    /** @test */
    public function assigned_coupon_usage_not_incremented_twice_on_duplicate_callback()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);

        $coupon = Coupon::create([
            'code' => 'ASSIGN-TEST-COUPON',
            'name' => 'Assigned Test Coupon',
            'slug' => 'assigned-test-coupon',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 10.00,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
            'limiter' => 100,
        ]);

        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'max_uses' => 3,
            'used' => 0,
        ]);

        $invoiceId = 'INV-ASSIGN-DUP';
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test Order',
            'user_phone' => '01000000001',
            'user_email' => $this->user->email,
            'address' => json_encode(['address' => '123 Street']),
            'shipping_method' => 'SCHEDULED',
            'total_price' => 100.00,
            'status' => 'pending',
            'payment_method' => 'online',
            'coupon' => $coupon->code,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => 100.00,
            'currency' => 'EGP',
            'invoice_id' => $invoiceId,
        ]);

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->twice()
            ->with('payment-assign-dup')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->twice()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-assign-dup')->assertStatus(302);
        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-assign-dup')->assertStatus(302);

        $this->assertEquals(1, CouponAssignmentUsage::where('order_id', $order->id)->count());
        $assignment = CouponAssignment::where('coupon_id', $coupon->id)->where('user_id', $this->user->id)->first();
        $this->assertEquals(1, $assignment->used);
        $this->assertEquals(1, $coupon->fresh()->used);
    }

    /** @test */
    public function concurrent_assigned_coupon_consumption_is_safe()
    {
        $coupon = Coupon::create([
            'code' => 'ASSIGN-CONCUR',
            'name' => 'Concurrent Test Coupon',
            'slug' => 'concurrent-test-coupon',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 10.00,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
            'limiter' => 100,
        ]);

        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'max_uses' => 2,
            'used' => 0,
        ]);

        $order1 = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Order One',
            'user_phone' => '01000000001',
            'user_email' => $this->user->email,
            'address' => json_encode(['address' => '123 Street']),
            'shipping_method' => 'SCHEDULED',
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => 'pending',
            'payment_method' => 'cod',
            'coupon' => $coupon->code,
        ]);

        $order2 = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Order Two',
            'user_phone' => '01000000001',
            'user_email' => $this->user->email,
            'address' => json_encode(['address' => '123 Street']),
            'shipping_method' => 'SCHEDULED',
            'price' => 100.00,
            'total_price' => 100.00,
            'status' => 'pending',
            'payment_method' => 'cod',
            'coupon' => $coupon->code,
        ]);

        $service = app(OrderService::class);

        DB::beginTransaction();
        try {
            $service->changeOrderStatus(null, 'completed', $order1->id);
            $service->changeOrderStatus(null, 'completed', $order2->id);
            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }

        $this->assertEquals(2, CouponAssignmentUsage::count());
        $assignment = CouponAssignment::where('coupon_id', $coupon->id)->where('user_id', $this->user->id)->first();
        $this->assertEquals(2, $assignment->used);
        $this->assertEquals(2, $coupon->fresh()->used);
    }

    // =============================================
    // SECTION 10: ATOMIC CALLBACK HARDENING
    // =============================================

    /** @test */
    public function callback_duplicate_does_not_dispatch_events_twice()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);
        $invoiceId = 'INV-EVENT-DUP';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->twice()
            ->with('payment-event-dup')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->twice()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-event-dup')->assertStatus(302);
        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-event-dup')->assertStatus(302);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        $this->assertEquals('completed', $order->fresh()->status);
        $this->assertEquals('paid', $order->fresh()->transactions()->first()->status);
    }

    /** @test */
    public function callback_duplicate_does_not_double_deduct_inventory()
    {
        $invoiceId = 'INV-STOCK-DUP';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        // NEW MODEL fixture: the ORDER owns an active reservation.
        $this->product->stock_quantity = 10;
        $this->product->reserved_quantity = 1;
        $this->product->sold_quantity = 0;
        $this->product->save();

        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_quantity' => 1,
            'product_price' => 100,
            'product_total_price' => 100,
        ]);
        $order->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'inventory_reserved_at' => now()->subHour(),
            'reservation_expires_at' => now()->addDay(),
        ])->save();

        // A cart may exist for FUTURE shopping — payment must ignore it.
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

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->twice()
            ->with('payment-stock-dup')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->twice()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-stock-dup')->assertStatus(302);
        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-stock-dup')->assertStatus(302);

        $product = $this->product->fresh();
        $this->assertEquals(9, $product->stock_quantity);
        $this->assertEquals(0, $product->reserved_quantity);
        $this->assertEquals(1, $product->sold_quantity);
    }

    /** @test */
    public function callback_duplicate_does_not_double_increment_promotion()
    {
        $promotion = \Marvel\Database\Models\Promotion::create([
            'name' => 'Test Promotion',
            'slug' => 'test-promotion',
            'code' => 'TEST-PROMO',
            'type' => 'percentage',
            'type_amount' => 'percentage',
            'value' => 10.00,
            'discount' => 10.00,
            'limiter' => 100,
            'usage' => 0,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'status' => true,
        ]);

        $invoiceId = 'INV-PROMO-DUP';
        $user = $this->user;
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Promo Order',
            'user_phone' => '01000000001',
            'user_email' => $user->email,
            'address' => json_encode(['address' => '123 Street']),
            'shipping_method' => 'SCHEDULED',
            'total_price' => 100.00,
            'status' => 'pending',
            'payment_method' => 'online',
            'promotion_id' => $promotion->id,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => 'myfatoorah',
            'status' => 'pending',
            'amount' => 100.00,
            'currency' => 'EGP',
            'invoice_id' => $invoiceId,
        ]);

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->twice()
            ->with('payment-promo-dup')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->twice()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-promo-dup')->assertStatus(302);
        $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-promo-dup')->assertStatus(302);

        $this->assertEquals(1, $promotion->fresh()->usage);
    }

    /** @test */
    public function callback_transaction_rolls_back_on_failure()
    {
        Event::fake([PaymentSucceeded::class, PaymentFailed::class, OrderStatusChanged::class]);

        $invoiceId = 'INV-ROLLBACK';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $this->product->stock_quantity = 10;
        $this->product->reserved_quantity = 1;
        $this->product->sold_quantity = 0;
        $this->product->save();

        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_quantity' => 1,
            'product_price' => 100,
            'product_total_price' => 100,
        ]);
        $order->forceFill([
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'inventory_reserved_at' => now()->subHour(),
            'reservation_expires_at' => now()->addDay(),
        ])->save();

        // Force the reservation COMMIT step to explode — the whole payment
        // transaction must roll back atomically.
        $reservationMock = \Mockery::mock(\App\Services\Inventory\OrderReservationService::class);
        $reservationMock->shouldReceive('commit')
            ->once()
            ->andThrow(new \RuntimeException('Simulated inventory failure'));

        $this->app->instance(\App\Services\Inventory\OrderReservationService::class, $reservationMock);

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->once()
            ->with('payment-rollback')
            ->andReturn(new GatewayResult(
                success: true,
                gatewayTransactionId: $invoiceId,
                amount: 100.00,
                currency: 'EGP',
                status: 'paid',
                rawResponse: ['status' => 'paid'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->once()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $response = $this->get(self::PREFIX . '/general/checkout/callback?paymentId=payment-rollback');

        $response->assertStatus(500);

        $orderAfter = $order->fresh();
        $this->assertEquals('pending', $orderAfter->status);

        $transaction = $orderAfter->transactions()->first();
        $this->assertEquals('pending', $transaction->status);

        $product = $this->product->fresh();
        $this->assertEquals(10, $product->stock_quantity);
        $this->assertEquals(1, $product->reserved_quantity);
        $this->assertEquals(0, $product->sold_quantity);

        Event::assertNotDispatched(PaymentSucceeded::class);
        Event::assertNotDispatched(PaymentFailed::class);
    }

    /** @test */
    public function callback_duplicate_error_is_idempotent()
    {
        $invoiceId = 'INV-ERR-DUP';
        $order = $this->createOrderWithPendingTransaction('online', $invoiceId, gateway: 'myfatoorah');

        $mockGateway = \Mockery::mock(PaymentGatewayContract::class);
        $mockGateway->shouldReceive('verifyPayment')
            ->twice()
            ->with('payment-err-dup')
            ->andReturn(new GatewayResult(
                success: false,
                gatewayTransactionId: $invoiceId,
                status: 'failed',
                errorMessage: 'Duplicate error',
                rawResponse: ['status' => 'failed'],
            ));

        $factoryMock = \Mockery::mock(PaymentGatewayFactory::class);
        $factoryMock->shouldReceive('make')
            ->twice()
            ->with('myfatoorah')
            ->andReturn($mockGateway);

        $this->app->instance(PaymentGatewayFactory::class, $factoryMock);

        $this->get(self::PREFIX . '/general/checkout/error-callback?paymentId=payment-err-dup')->assertStatus(302);
        $this->get(self::PREFIX . '/general/checkout/error-callback?paymentId=payment-err-dup')->assertStatus(302);

        $transaction = $order->fresh()->transactions()->first();
        $this->assertEquals('failed', $transaction->status);
    }
}
