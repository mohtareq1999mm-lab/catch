<?php

namespace Tests\Feature;

use App\Console\Commands\CancelUnpaidOrders;
use App\Services\General\OrderService;
use App\Services\Payment\PaymentCheckoutHandler;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
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
use Marvel\Database\Models\Country;
use Marvel\Enums\ShippingMethod;
use App\Events\OrderCancelled;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

class PaymentSystemTest extends TestCase
{

    use DatabaseTransactions, WithInvoiceTables;

    private const PREFIX = '/api/v1';

    private User $user;
    private User $admin;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        if (!Schema::hasTable('products')) {
            $this->createAllTables();
        }

        $this->createInvoiceTables();

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

        Schema::create('coupon_usages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('coupon_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->timestamp('used_at')->nullable();
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

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
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
        ]);

        \Marvel\Database\Models\Settings::create([
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

        $cart->load(['items', 'items.product']);
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

    private function createOrderWithPendingTransaction(string $paymentMethod = 'cod', ?string $invoiceId = null): Order
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Test Order',
            'price' => 100.00,
            'total_price' => 100.00,
            'payment_method' => $paymentMethod,
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => $paymentMethod,
            'status' => 'pending',
            'amount' => 100.00,
            'invoice_id' => $invoiceId,
        ]);

        return $order->fresh();
    }

    // ========== OrderService: markCashierPaid ==========

    /** @test */
    public function mark_cashier_as_paid_updates_transaction_and_order()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrderWithPendingTransaction('pay_at_cashier');

        $orderService = app(OrderService::class);
        $orderService->markCashierPaid($order);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'pay_at_cashier',
            'status' => 'paid',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);
    }

    /** @test */
    public function mark_cashier_as_paid_fires_payment_success_event()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrderWithPendingTransaction('pay_at_cashier');

        $orderService = app(OrderService::class);
        $orderService->markCashierPaid($order);

        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->order->id === $order->id);
    }

    /** @test */
    public function mark_cashier_as_paid_throws_exception_without_pending_transaction()
    {
        $this->expectException(\RuntimeException::class);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Cashier Order',
            'total_price' => 100,
            'payment_method' => 'pay_at_cashier',
            'status' => 'pending',
        ]);

        $orderService = app(OrderService::class);
        $orderService->markCashierPaid($order);
    }

    /** @test */
    public function mark_cashier_as_paid_ignores_paid_transactions()
    {
        $this->expectException(\RuntimeException::class);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Cashier Order',
            'total_price' => 100,
            'payment_method' => 'pay_at_cashier',
            'status' => 'completed',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'pay_at_cashier',
            'status' => 'paid',
            'amount' => 100,
            'paid_at' => now(),
        ]);

        $orderService = app(OrderService::class);
        $orderService->markCashierPaid($order);
    }

    /** @test */
    public function mark_cashier_as_paid_ignores_other_payment_methods()
    {
        $this->expectException(\RuntimeException::class);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Mixed Order',
            'total_price' => 100,
            'payment_method' => 'pay_at_cashier',
            'status' => 'pending',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markCashierPaid($order);
    }

    // ========== OrderService: markCodAsPaid (regression with coupon recording) ==========

    /** @test */
    public function mark_cod_as_paid_records_coupon_usage()
    {
        Event::fake([PaymentSucceeded::class]);

        $coupon = \Marvel\Database\Models\Coupon::create([
            'slug' => 'test10',
            'discount_type' => 'fixed',
            'discount' => 10,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
            'status' => true,
        ]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'COD With Coupon',
            'total_price' => 100,
            'payment_method' => 'cod',
            'status' => 'pending',
            'coupon' => $coupon->code,
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => 100,
        ]);

        $orderService = app(OrderService::class);
        $orderService->markCodAsPaid($order);

        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'order_id' => $order->id,
        ]);
    }

    // ========== OrderService: changeOrderStatus with orderId fallback ==========

    /** @test */
    public function change_order_status_works_with_order_id_when_invoice_id_is_null()
    {
        Event::fake([OrderStatusChanged::class]);
        $order = $this->createOrderWithPendingTransaction('cod');

        $orderService = app(OrderService::class);
        $result = $orderService->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertNotFalse($result);
        $this->assertEquals('cancelled', $result->status);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => 'failed',
        ]);

        Event::assertDispatched(OrderStatusChanged::class);
    }

    /** @test */
    public function change_order_status_still_works_with_invoice_id()
    {
        Event::fake([OrderStatusChanged::class]);
        $order = $this->createOrderWithPendingTransaction('myfatoorah', 'INV-123');

        $orderService = app(OrderService::class);
        $result = $orderService->changeOrderStatus('INV-123', 'completed');

        $this->assertNotFalse($result);
        $this->assertEquals('completed', $result->status);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => 'paid',
        ]);

        Event::assertDispatched(OrderStatusChanged::class);
    }

    /** @test */
    public function change_order_status_returns_false_when_no_order_found()
    {
        $orderService = app(OrderService::class);
        $result = $orderService->changeOrderStatus(null, 'cancelled', 99999);

        $this->assertFalse($result);
    }

    // ========== API: Mark COD as Paid ==========

    /** @test */
    public function mark_cod_as_paid_endpoint_requires_auth()
    {
        $response = $this->postJson(self::PREFIX . '/general/checkout/cod/1/mark-paid');
        $response->assertStatus(401);
    }

    /** @test */
    public function mark_cod_as_paid_endpoint_requires_update_order_status_permission()
    {
        Sanctum::actingAs($this->user);

        $order = $this->createOrderWithPendingTransaction('cod');

        $response = $this->postJson(self::PREFIX . "/general/checkout/cod/{$order->id}/mark-paid");
        $response->assertStatus(403);
    }

    /** @test */
    public function mark_cod_as_paid_endpoint_succeeds_for_admin()
    {
        Event::fake([PaymentSucceeded::class]);

        $this->setupAdminPermissions();

        $order = $this->createOrderWithPendingTransaction('cod');

        $response = $this->postJson(self::PREFIX . "/general/checkout/cod/{$order->id}/mark-paid");
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function mark_cod_as_paid_endpoint_returns_422_for_no_pending_transaction()
    {
        $this->setupAdminPermissions();

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'No Trans',
            'total_price' => 100,
            'payment_method' => 'cod',
            'status' => 'pending',
        ]);

        $response = $this->postJson(self::PREFIX . "/general/checkout/cod/{$order->id}/mark-paid");
        $response->assertStatus(422);
    }

    // ========== API: Mark Cashier Paid ==========

    /** @test */
    public function mark_paid_endpoint_requires_auth()
    {
        $response = $this->postJson(self::PREFIX . '/general/checkout/cashier/1/mark-paid');
        $response->assertStatus(401);
    }

    /** @test */
    public function mark_paid_endpoint_requires_update_order_status_permission()
    {
        Sanctum::actingAs($this->user);

        $order = $this->createOrderWithPendingTransaction('pay_at_cashier');

        $response = $this->postJson(self::PREFIX . "/general/checkout/cashier/{$order->id}/mark-paid");
        $response->assertStatus(403);
    }

    /** @test */
    public function mark_paid_endpoint_succeeds_for_admin()
    {
        Event::fake([PaymentSucceeded::class]);

        $this->setupAdminPermissions();

        $order = $this->createOrderWithPendingTransaction('pay_at_cashier');

        $response = $this->postJson(self::PREFIX . "/general/checkout/cashier/{$order->id}/mark-paid");
        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
        ]);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'status' => 'paid',
        ]);
    }

    /** @test */
    public function mark_paid_endpoint_returns_422_for_no_pending_transaction()
    {
        $this->setupAdminPermissions();

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'No Trans',
            'total_price' => 100,
            'payment_method' => 'pay_at_cashier',
            'status' => 'pending',
        ]);

        $response = $this->postJson(self::PREFIX . "/general/checkout/cashier/{$order->id}/mark-paid");
        $response->assertStatus(422);
    }

    /** @test */
    public function mark_paid_endpoint_returns_404_for_nonexistent_order()
    {
        $this->setupAdminPermissions();

        $response = $this->postJson(self::PREFIX . '/general/checkout/cashier/99999/mark-paid');
        $response->assertStatus(404);
    }

    // ========== CancelUnpaidOrders Command ==========

    /** @test */
    public function cancel_unpaid_orders_cancels_expired_pending_orders()
    {
        Event::fake([PaymentFailed::class]);

        Config::set('payment.order_timeout_hours', 1);

        $freshOrder = $this->createOrderWithPendingTransaction('cod');
        DB::table('orders')->where('id', $freshOrder->id)->update(['created_at' => now()]);

        $expiredOrder = $this->createOrderWithPendingTransaction('pay_at_cashier');
        DB::table('orders')->where('id', $expiredOrder->id)->update(['created_at' => now()->subHours(2)]);

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $freshOrder->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $expiredOrder->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $expiredOrder->id,
            'status' => 'failed',
        ]);
    }

    /** @test */
    public function cancel_unpaid_orders_skips_completed_orders()
    {
        Config::set('payment.order_timeout_hours', 1);

        $completedOrder = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Completed',
            'total_price' => 100,
            'status' => 'completed',
        ]);
        DB::table('orders')->where('id', $completedOrder->id)->update(['created_at' => now()->subDays(10)]);

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $completedOrder->id,
            'status' => 'completed',
        ]);
    }

    /** @test */
    public function cancel_unpaid_orders_fires_payment_failed_event()
    {
        Event::fake([PaymentFailed::class]);

        Config::set('payment.order_timeout_hours', 1);

        $order = $this->createOrderWithPendingTransaction('cod');
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]);

        $this->artisan('orders:cancel-unpaid');

        Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->order->id === $order->id);
    }

    /** @test */
    public function cancel_unpaid_orders_uses_configurable_timeout()
    {
        Config::set('payment.order_timeout_hours', 48);

        $recentOrder = $this->createOrderWithPendingTransaction('cod');
        DB::table('orders')->where('id', $recentOrder->id)->update(['created_at' => now()->subHours(24)]);

        $oldOrder = $this->createOrderWithPendingTransaction('cod');
        DB::table('orders')->where('id', $oldOrder->id)->update(['created_at' => now()->subHours(72)]);

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $recentOrder->id,
            'status' => 'pending',
        ]);

        $this->assertDatabaseHas('orders', [
            'id' => $oldOrder->id,
            'status' => 'cancelled',
        ]);
    }

    /** @test */
    public function cancel_unpaid_orders_releases_cart_reservation()
    {
        Config::set('payment.order_timeout_hours', 1);
        $cart = $this->createActiveCart();

        $cartItem = $cart->items->first();
        $cartItem->update(['reserved_quantity' => 1]);
        $this->product->update(['reserved_quantity' => 1]);

        $order = $this->createOrderWithPendingTransaction('online', 'inv-cart-release');
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]);

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'reserved_quantity' => 1,
        ]);

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'reserved_quantity' => 0,
        ]);
    }

    /** @test */
    public function cancel_unpaid_orders_skips_release_when_cart_already_checked_out()
    {
        Config::set('payment.order_timeout_hours', 1);

        $cart = $this->createActiveCart();
        $cart->update(['status' => 'checked_out']);

        $this->product->update(['reserved_quantity' => 1]);

        $order = $this->createOrderWithPendingTransaction('cod');
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]);

        $this->artisan('orders:cancel-unpaid')
            ->assertExitCode(0);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);

        // Reservation should remain unchanged (cart was already checked_out, no active cart to release)
        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'reserved_quantity' => 1,
        ]);
    }

    // ========== Pickup Validation: pickup_location_id required when fulfillment_type=pickup ==========

    /** @test */
    public function checkout_requires_pickup_location_id_for_pickup_fulfillment()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();
        $this->createPickupLocation();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['pickup_location_id']);
    }

    /** @test */
    public function fast_checkout_requires_pickup_location_id_for_pickup_fulfillment()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();
        $this->createPickupLocation();

        $response = $this->postJson(self::PREFIX . '/general/fast-shipping/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'governorate_id' => 1,
            'fulfillment_type' => 'pickup',
            'payment_method' => 'pay_at_cashier',
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['pickup_location_id']);
    }

    /** @test */
    public function checkout_does_not_require_pickup_location_id_for_delivery()
    {
        Sanctum::actingAs($this->user);
        $this->createActiveCart();

        $response = $this->postJson(self::PREFIX . '/general/checkout', [
            'name' => 'Test User',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => ['street' => 'Test St'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'cod',
            'governorate_id' => 1,
        ]);

        $response->assertStatus(200);
    }

    // ========== PaymentCheckoutHandler: Direct Unit Tests ==========

    /** @test */
    public function payment_checkout_handler_cod_creates_transaction()
    {
        $order = $this->createOrderWithPendingTransaction('cod');
        $order->update(['total_price' => 200.00]);
        Transaction::where('order_id', $order->id)->delete();

        $handler = app(PaymentCheckoutHandler::class);
        $request = Request::create('/dummy', 'POST', [], [], [], [], null);
        $request->setUserResolver(fn () => $this->user);

        $response = $handler->handleCodPayment($request, $order->fresh());
        $this->assertEquals(200, $response->status());
        $this->assertTrue($response->getData()->success);

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => '200.00',
        ]);
    }

    /** @test */
    public function payment_checkout_handler_uses_configured_currency()
    {
        Config::set('payment.default_currency', 'KWD');

        $order = $this->createOrderWithPendingTransaction('cod');
        Transaction::where('order_id', $order->id)->delete();

        $handler = app(PaymentCheckoutHandler::class);
        $request = Request::create('/dummy', 'POST', [], [], [], [], null);
        $request->setUserResolver(fn () => $this->user);

        $handler->handleCodPayment($request, $order->fresh());

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'currency' => 'KWD',
        ]);
    }

    // ========== Helpers ==========

    private function setupAdminPermissions(): void
    {
        $permission = \Spatie\Permission\Models\Permission::create(['name' => 'update-order-status']);
        $this->admin->givePermissionTo($permission);
        Sanctum::actingAs($this->admin);
    }
}
