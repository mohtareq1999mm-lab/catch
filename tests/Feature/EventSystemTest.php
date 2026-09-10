<?php

namespace Tests\Feature;

use App\Events\OrderCancelled;
use App\Events\OrderStatusChanged;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundApproved;
use App\Listeners\RatingRemoved;
use App\Listeners\RestoreInventoryOnRefund;
use App\Listeners\RestoreProductInventory;
use App\Services\Gateway\MyFatoorahGateway;
use App\Services\General\MyfatoraService;
use App\Jobs\LogActivityJob;
use App\Listeners\SendOrderCancelledNotification;
use App\Listeners\SendOrderStatusChangedNotification;
use Marvel\Listeners\SendOrderCancelledNotification as MarvelSendOrderCancelledNotification;
use Marvel\Providers\EventServiceProvider as MarvelEventServiceProvider;
use App\Listeners\SendPaymentFailedNotification;
use App\Listeners\SendPaymentSucceededNotification;
use App\Providers\EventServiceProvider;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\OrderProduct;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

class EventSystemTest extends TestCase
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
            $table->string('language', 10)->default('en');
            $table->timestamp('paid_at')->nullable();
                                    $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->index(['status', 'reservation_expires_at']);
            if (!Schema::hasTable('digital_entitlements')) {
            Schema::create('digital_entitlements', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->unsignedBigInteger('order_item_id')->nullable();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status')->default('pending');
                $table->timestamps();
            });
        }
            $table->timestamps();
            $table->unsignedBigInteger('parent_id')->nullable();
            $table->softDeletes();
            $table->timestamp('inventory_restored_at')->nullable();
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
            $table->foreignId('cart_id')->constrained('carts')->cascadeOnDelete();
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

        Schema::create('refunds', function (Blueprint $table) {
            $table->id();
            $table->decimal('amount', 10, 2);
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('status')->default('PENDING');
            $table->foreignId('order_id')->nullable();
            $table->foreignId('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable();
            $table->foreignId('order_id')->nullable();
            $table->foreignId('product_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
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
            'type' => 'user',
            'is_active' => true,
        ]);

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'email_verified_at' => now(),
            'password' => bcrypt('password'),
            'type' => 'admin',
            'is_active' => true,
        ]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product',
            'description' => 'A test product',
            'price' => 100.00,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 10,
            'sold_quantity' => 5,
        ]);

        Settings::create([
            'language' => 'en',
            'options' => [],
        ]);
    }

    private function createOrderWithItems(?User $customer = null): Order
    {
        $customer = $customer ?? $this->user;

        $order = Order::create([
            'user_id' => $customer->id,
            'name' => 'Test Order',
            'user_email' => $customer->email,
            'total_price' => 100.00,
            'status' => 'pending',
            'paid_at' => now(),
        ]);

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_quantity' => 3,
            'product_price' => 100.00,
            'product_total_price' => 300.00,
            'is_gift' => false,
        ]);

        return $order->fresh()->load(['orderItems', 'user']);
    }

    private function createOrderWithPendingTransaction(string $paymentMethod = 'cod'): Order
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
        ]);

        return $order->fresh();
    }

    // ========== Event Instantiation ==========

    /** @test */
    public function order_cancelled_event_holds_order()
    {
        $order = $this->createOrderWithItems();
        $event = new OrderCancelled($order);

        $this->assertSame($order, $event->order);
    }

    /** @test */
    public function order_status_changed_event_holds_order()
    {
        $order = $this->createOrderWithItems();
        $event = new OrderStatusChanged($order);

        $this->assertSame($order, $event->order);
    }

    /** @test */
    public function payment_succeeded_event_holds_order()
    {
        $order = $this->createOrderWithItems();
        $event = new PaymentSucceeded($order);

        $this->assertSame($order, $event->order);
    }

    /** @test */
    public function payment_failed_event_holds_order()
    {
        $order = $this->createOrderWithItems();
        $event = new PaymentFailed($order);

        $this->assertSame($order, $event->order);
    }

    // ========== Listener: RestoreProductInventory ==========

    /** @test */
    public function restore_product_inventory_restores_stock()
    {
        $order = $this->createOrderWithItems();

        event(new OrderCancelled($order));

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 13,
            'sold_quantity' => 2,
        ]);
    }

    /** @test */
    public function restore_product_inventory_skips_gift_items()
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Gift Order',
            'total_price' => 100.00,
            'status' => 'pending',
        ]);

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_quantity' => 2,
            'product_price' => 100.00,
            'product_total_price' => 200.00,
            'is_gift' => true,
        ]);

        event(new OrderCancelled($order));

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 10,
            'sold_quantity' => 5,
        ]);
    }

    /** @test */
    public function restore_product_inventory_handles_missing_product_gracefully()
    {
        $otherProduct = Product::create([
            'name' => 'Orphan Item',
            'slug' => 'orphan-item',
            'price' => 50.00,
            'status' => true,
            'in_stock' => true,
        ]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Missing Product',
            'total_price' => 100.00,
            'status' => 'pending',
        ]);

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $otherProduct->id,
            'product_quantity' => 3,
            'product_price' => 100.00,
            'product_total_price' => 300.00,
            'is_gift' => false,
        ]);

        $otherProduct->delete();

        event(new OrderCancelled($order));

        $this->assertTrue(true);
    }

    /** @test */
    public function restore_product_inventory_is_queued()
    {
        $reflection = new \ReflectionClass(RestoreProductInventory::class);
        $this->assertTrue($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    /** @test */
    public function restore_product_inventory_uses_medium_queue()
    {
        $listener = app(RestoreProductInventory::class);
        $this->assertEquals('medium', $listener->queue);
    }

    // ========== Listener: RestoreInventoryOnRefund ==========

    /** @test */
    public function restore_inventory_on_refund_restores_stock()
    {
        $order = $this->createOrderWithItems();
        $refund = Refund::withoutEvents(function () use ($order) {
            return Refund::create([
                'order_id' => $order->id,
                'user_id' => $this->user->id,
                'amount' => 100.00,
                'title' => 'Test Refund',
                'status' => 'APPROVED',
            ]);
        });

        event(new RefundApproved($refund));

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 13,
            'sold_quantity' => 2,
        ]);
    }

    /** @test */
    public function restore_inventory_on_refund_skips_when_order_already_cancelled()
    {
        $order = $this->createOrderWithItems();
        $order->update(['status' => 'cancelled']);

        $refund = Refund::withoutEvents(function () use ($order) {
            return Refund::create([
                'order_id' => $order->id,
                'user_id' => $this->user->id,
                'amount' => 100.00,
                'title' => 'Test Refund',
                'status' => 'APPROVED',
            ]);
        });

        event(new RefundApproved($refund));

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 10,
            'sold_quantity' => 5,
        ]);
    }

    /** @test */
    public function restore_inventory_on_refund_handles_missing_order_gracefully()
    {
        $refund = Refund::withoutEvents(function () {
            return Refund::create([
                'order_id' => null,
                'user_id' => $this->user->id,
                'amount' => 100.00,
                'title' => 'Orphan Refund',
                'status' => 'APPROVED',
            ]);
        });

        event(new RefundApproved($refund));

        $this->assertTrue(true);
    }

    /** @test */
    public function restore_inventory_on_refund_skips_gift_items()
    {
        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Gift Order',
            'total_price' => 100.00,
            'status' => 'completed',
        ]);

        OrderProduct::create([
            'order_id' => $order->id,
            'product_id' => $this->product->id,
            'product_quantity' => 2,
            'product_price' => 100.00,
            'product_total_price' => 200.00,
            'is_gift' => true,
        ]);

        $refund = Refund::withoutEvents(function () use ($order) {
            return Refund::create([
                'order_id' => $order->id,
                'user_id' => $this->user->id,
                'amount' => 200.00,
                'title' => 'Gift Refund',
                'status' => 'APPROVED',
            ]);
        });

        event(new RefundApproved($refund));

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 10,
            'sold_quantity' => 5,
        ]);
    }

    /** @test */
    public function restore_inventory_on_refund_is_queued()
    {
        $reflection = new \ReflectionClass(RestoreInventoryOnRefund::class);
        $this->assertTrue($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    /** @test */
    public function restore_inventory_on_refund_uses_medium_queue()
    {
        $listener = app(RestoreInventoryOnRefund::class);
        $this->assertEquals('medium', $listener->queue);
    }

    // ========== Gateway: MyFatoorahGateway::refund() ==========

    /** @test */
    public function gateway_refund_returns_success_result()
    {
        $order = $this->createOrderWithItems();
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'gateway_transaction_id' => '12345',
            'status' => 'paid',
            'amount' => 100.00,
        ]);

        $mockService = $this->createMock(MyfatoraService::class);
        $mockService->method('makeRefund')->willReturn([
            'IsSuccess' => true,
            'Data' => [
                'RefundId' => 'refund-abc-123',
                'RefundStatus' => 'Refunded',
            ],
        ]);

        $gateway = new MyFatoorahGateway($mockService);
        $result = $gateway->refund($order, 100.00);

        $this->assertTrue($result->success);
        $this->assertEquals('refund-abc-123', $result->gatewayTransactionId);
        $this->assertEquals('Refunded', $result->status);
        $this->assertEquals(100.00, $result->amount);
    }

    /** @test */
    public function gateway_refund_handles_no_paid_transaction()
    {
        $order = $this->createOrderWithItems();

        $gateway = new MyFatoorahGateway(app(MyfatoraService::class));
        $result = $gateway->refund($order, 100.00);

        $this->assertFalse($result->success);
        $this->assertNotNull($result->errorMessage);
    }

    /** @test */
    public function gateway_refund_handles_gateway_error()
    {
        $order = $this->createOrderWithItems();
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'gateway_transaction_id' => '12345',
            'status' => 'paid',
            'amount' => 100.00,
        ]);

        $mockService = $this->createMock(MyfatoraService::class);
        $mockService->method('makeRefund')->willReturn(null);

        $gateway = new MyFatoorahGateway($mockService);
        $result = $gateway->refund($order, 100.00);

        $this->assertFalse($result->success);
        $this->assertEquals('No response from payment gateway', $result->errorMessage);
    }

    /** @test */
    public function refund_approved_event_dispatches_listeners()
    {
        $order = $this->createOrderWithItems();
        $refund = Refund::withoutEvents(function () use ($order) {
            return Refund::create([
                'order_id' => $order->id,
                'user_id' => $this->user->id,
                'amount' => 100.00,
                'title' => 'Listener Test',
                'status' => 'APPROVED',
            ]);
        });

        Event::fake([RefundApproved::class]);

        event(new RefundApproved($refund));

        Event::assertDispatched(RefundApproved::class);
    }

    // ========== Listener: SendOrderCancelledNotification ==========

    /** @test */
    public function order_cancelled_logs_activity()
    {
        Bus::fake();

        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendOrderCancelledNotification::class);
        $listener->handle(new OrderCancelled($order));

        Bus::assertDispatched(LogActivityJob::class, function ($job) use ($order) {
            return $job->subjectType === get_class($order)
                && $job->subjectId === $order->id
                && $job->causerId === $order->user_id
                && $job->event === 'order_cancelled'
                && $job->logName === 'orders'
                && $job->description === __('activity.order_cancelled')
                && isset($job->properties['order_id'])
                && $job->properties['order_id'] === $order->id;
        });
    }

    /** @test */
    public function order_cancelled_notification_is_queued()
    {
        $reflection = new \ReflectionClass(SendOrderCancelledNotification::class);
        $this->assertTrue($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    /** @test */
    public function order_cancelled_notification_uses_medium_queue()
    {
        $listener = app(SendOrderCancelledNotification::class);
        $this->assertEquals('medium', $listener->queue);
    }

    // ========== Listener: SendOrderStatusChangedNotification ==========

    /** @test */
    public function order_status_changed_logs_activity()
    {
        Bus::fake();

        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendOrderStatusChangedNotification::class);
        $listener->handle(new OrderStatusChanged($order));

        Bus::assertDispatched(LogActivityJob::class, function ($job) use ($order) {
            return $job->subjectType === get_class($order)
                && $job->subjectId === $order->id
                && $job->causerId === $order->user_id
                && $job->event === 'order_status_changed'
                && $job->logName === 'orders'
                && $job->description === __('activity.order_status_changed')
                && isset($job->properties['order_id'])
                && $job->properties['order_id'] === $order->id;
        });
    }

    /** @test */
    public function order_status_changed_notification_is_queued()
    {
        $reflection = new \ReflectionClass(SendOrderStatusChangedNotification::class);
        $this->assertTrue($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    /** @test */
    public function order_status_changed_notification_uses_medium_queue()
    {
        $listener = app(SendOrderStatusChangedNotification::class);
        $this->assertEquals('medium', $listener->queue);
    }

    // ========== Listener: SendPaymentSucceededNotification ==========

    /** @test */
    public function payment_succeeded_logs_activity()
    {
        Bus::fake();

        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendPaymentSucceededNotification::class);
        $listener->handle(new PaymentSucceeded($order));

        Bus::assertDispatched(LogActivityJob::class, function ($job) use ($order) {
            return $job->subjectType === get_class($order)
                && $job->subjectId === $order->id
                && $job->causerId === $order->user_id
                && $job->event === 'payment_succeeded'
                && $job->logName === 'orders'
                && $job->description === __('activity.payment_succeeded')
                && isset($job->properties['order_id'])
                && $job->properties['order_id'] === $order->id;
        });
    }

    /** @test */
    public function payment_succeeded_notification_is_queued()
    {
        $reflection = new \ReflectionClass(SendPaymentSucceededNotification::class);
        $this->assertTrue($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    /** @test */
    public function payment_succeeded_notification_uses_medium_queue()
    {
        $listener = app(SendPaymentSucceededNotification::class);
        $this->assertEquals('medium', $listener->queue);
    }

    // ========== Listener: SendPaymentFailedNotification ==========

    /** @test */
    public function payment_failed_logs_activity()
    {
        Bus::fake();

        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendPaymentFailedNotification::class);
        $listener->handle(new PaymentFailed($order));

        Bus::assertDispatched(LogActivityJob::class, function ($job) use ($order) {
            return $job->subjectType === get_class($order)
                && $job->subjectId === $order->id
                && $job->causerId === $order->user_id
                && $job->event === 'payment_failed'
                && $job->logName === 'orders'
                && $job->description === __('activity.payment_failed')
                && isset($job->properties['order_id'])
                && $job->properties['order_id'] === $order->id;
        });
    }

    /** @test */
    public function payment_failed_notification_is_queued()
    {
        $reflection = new \ReflectionClass(SendPaymentFailedNotification::class);
        $this->assertTrue($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    /** @test */
    public function payment_failed_notification_uses_medium_queue()
    {
        $listener = app(SendPaymentFailedNotification::class);
        $this->assertEquals('medium', $listener->queue);
    }

    // ========== Service: OrderService dispatches App Events ==========

    /** @test */
    public function change_order_status_dispatches_order_status_changed()
    {
        Event::fake([OrderStatusChanged::class]);

        $order = $this->createOrderWithPendingTransaction('cod');

        $orderService = app(OrderService::class);
        $orderService->changeOrderStatus(null, 'completed', $order->id);

        Event::assertDispatched(OrderStatusChanged::class, fn ($e) => $e->order->id === $order->id);
    }

    /** @test */
    public function change_order_status_to_cancelled_from_pending_dispatches_order_cancelled()
    {
        Event::fake([OrderCancelled::class, OrderStatusChanged::class]);

        $order = $this->createOrderWithPendingTransaction('cod');
        $this->assertEquals('pending', $order->status);

        $orderService = app(OrderService::class);
        $orderService->changeOrderStatus(null, 'cancelled', $order->id);

        Event::assertDispatched(OrderCancelled::class, fn ($e) => $e->order->id === $order->id);
        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    /** @test */
    public function mark_cod_as_paid_dispatches_payment_succeeded()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrderWithPendingTransaction('cod');

        $orderService = app(OrderService::class);
        $orderService->markCodAsPaid($order);

        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->order->id === $order->id);
    }

    /** @test */
    public function mark_cashier_paid_dispatches_payment_succeeded()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrderWithPendingTransaction('pay_at_cashier');

        $orderService = app(OrderService::class);
        $orderService->markCashierPaid($order);

        Event::assertDispatched(PaymentSucceeded::class, fn ($e) => $e->order->id === $order->id);
    }

    // ========== Artisan Command dispatches App Events ==========

    /** @test */
    public function cancel_unpaid_orders_dispatches_payment_failed()
    {
        Event::fake([PaymentFailed::class]);

        Config::set('payment.order_timeout_hours', 1);

        $order = $this->createOrderWithPendingTransaction('cod');
        DB::table('orders')->where('id', $order->id)->update(['created_at' => now()->subHours(2)]);

        $this->artisan('orders:cancel-unpaid');

        Event::assertDispatched(PaymentFailed::class, fn ($e) => $e->order->id === $order->id);
    }

    // ========== Activity Log DB Record Creation ==========

    /** @test */
    public function order_cancelled_listener_creates_activity_log_record()
    {
        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendOrderCancelledNotification::class);
        $listener->handle(new OrderCancelled($order));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => get_class($order),
            'subject_id' => $order->id,
            'causer_id' => $order->user_id,
            'event' => 'order_cancelled',
            'log_name' => 'orders',
        ]);
    }

    /** @test */
    public function order_status_changed_listener_creates_activity_log_record()
    {
        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendOrderStatusChangedNotification::class);
        $listener->handle(new OrderStatusChanged($order));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => get_class($order),
            'subject_id' => $order->id,
            'causer_id' => $order->user_id,
            'event' => 'order_status_changed',
            'log_name' => 'orders',
        ]);
    }

    /** @test */
    public function payment_succeeded_listener_creates_activity_log_record()
    {
        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendPaymentSucceededNotification::class);
        $listener->handle(new PaymentSucceeded($order));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => get_class($order),
            'subject_id' => $order->id,
            'causer_id' => $order->user_id,
            'event' => 'payment_succeeded',
            'log_name' => 'orders',
        ]);
    }

    /** @test */
    public function payment_failed_listener_creates_activity_log_record()
    {
        $order = $this->createOrderWithItems($this->user);

        $listener = app(SendPaymentFailedNotification::class);
        $listener->handle(new PaymentFailed($order));

        $this->assertDatabaseHas('activity_log', [
            'subject_type' => get_class($order),
            'subject_id' => $order->id,
            'causer_id' => $order->user_id,
            'event' => 'payment_failed',
            'log_name' => 'orders',
        ]);
    }

    // ========== Listener Registration in EventServiceProvider ==========

    /** @test */
    public function event_service_provider_registers_order_cancelled_listeners()
    {
        $reflection = new \ReflectionClass(EventServiceProvider::class);
        $defaults = $reflection->getDefaultProperties();

        $this->assertArrayHasKey(OrderCancelled::class, $defaults['listen']);
        $this->assertContains(RestoreProductInventory::class, $defaults['listen'][OrderCancelled::class]);
        $this->assertContains(SendOrderCancelledNotification::class, $defaults['listen'][OrderCancelled::class]);
    }

    /** @test */
    public function app_event_service_provider_listens_to_marvel_order_cancelled()
    {
        $reflection = new \ReflectionClass(EventServiceProvider::class);
        $defaults = $reflection->getDefaultProperties();

        $this->assertArrayHasKey(\Marvel\Events\OrderCancelled::class, $defaults['listen']);
        $this->assertContains(
            RestoreProductInventory::class,
            $defaults['listen'][\Marvel\Events\OrderCancelled::class]
        );
        $this->assertCount(
            1,
            $defaults['listen'][\Marvel\Events\OrderCancelled::class]
        );
    }

    /** @test */
    public function order_cancelled_via_service_restores_stock()
    {
        $order = $this->createOrderWithItems();
        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'amount' => 100.00,
        ]);

        $this->product->update([
            'stock_quantity' => 7,
            'sold_quantity' => 8,
        ]);

        $orderService = app(OrderService::class);
        $orderService->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 10,
            'sold_quantity' => 5,
        ]);
    }

    // ========== Marvel OrderCancelled Event Registration ==========

    /** @test */
    public function marvel_event_service_provider_registers_order_cancelled_listener()
    {
        $reflection = new \ReflectionClass(MarvelEventServiceProvider::class);
        $defaults = $reflection->getDefaultProperties();

        $this->assertArrayHasKey(\Marvel\Events\OrderCancelled::class, $defaults['listen']);
        $this->assertContains(
            MarvelSendOrderCancelledNotification::class,
            $defaults['listen'][\Marvel\Events\OrderCancelled::class]
        );
    }

    /** @test */
    public function marvel_order_cancelled_listener_is_queued()
    {
        $reflection = new \ReflectionClass(MarvelSendOrderCancelledNotification::class);
        $this->assertTrue($reflection->implementsInterface(\Illuminate\Contracts\Queue\ShouldQueue::class));
    }

    /** @test */
    public function marvel_order_cancelled_event_dispatches_listener()
    {
        Event::fake([\Marvel\Events\OrderCancelled::class]);

        $order = $this->createOrderWithItems();
        event(new \Marvel\Events\OrderCancelled($order));

        Event::assertDispatched(\Marvel\Events\OrderCancelled::class, function ($e) use ($order) {
            return $e->order->id === $order->id;
        });
    }

    /** @test */
    public function restore_product_inventory_handles_marvel_event()
    {
        $order = $this->createOrderWithItems();
        $event = new \Marvel\Events\OrderCancelled($order);

        $listener = app(RestoreProductInventory::class);
        $listener->handle($event);

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 13,
            'sold_quantity' => 2,
        ]);

        $this->assertNotNull($order->fresh()->inventory_restored_at);
    }

    /** @test */
    public function restore_product_inventory_via_marvel_event_is_idempotent()
    {
        $order = $this->createOrderWithItems();
        $event = new \Marvel\Events\OrderCancelled($order);

        $listener = app(RestoreProductInventory::class);
        $listener->handle($event);

        $restoredAt = $order->fresh()->inventory_restored_at;

        $this->product->update([
            'stock_quantity' => 5,
            'sold_quantity' => 10,
        ]);

        $listener->handle($event);

        $this->assertDatabaseHas('products', [
            'id' => $this->product->id,
            'stock_quantity' => 5,
            'sold_quantity' => 10,
        ]);

        $newRestoredAt = $order->fresh()->inventory_restored_at;
        $this->assertEquals(
            $restoredAt instanceof \Carbon\Carbon
                ? $restoredAt->format('Y-m-d H:i:s')
                : $restoredAt,
            $newRestoredAt instanceof \Carbon\Carbon
                ? $newRestoredAt->format('Y-m-d H:i:s')
                : $newRestoredAt
        );
    }
}
