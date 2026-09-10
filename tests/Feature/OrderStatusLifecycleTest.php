<?php

namespace Tests\Feature;

use App\Events\OrderCancelled;
use App\Events\OrderDelivered;
use App\Events\OrderStatusChanged;
use App\Events\PaymentSucceeded;
use App\Models\Invoice;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\TestCase;

class OrderStatusLifecycleTest extends TestCase
{

    use DatabaseTransactions;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        if (!Schema::hasTable('users')) {
            $this->createTables();
        }
        $this->seedBaseData();
    }

    private function createTables(): void
    {
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

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status')->default('pending');
            $table->string('payment_status')->nullable();
            $table->string('fulfillment_status')->nullable();
            $table->string('fulfillment_type')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->decimal('shipping_price', 10, 2)->nullable();
            $table->text('address')->nullable();
            $table->unsignedBigInteger('pickup_location_id')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->boolean('coupon_consumed')->default(false);
            $table->boolean('promotion_consumed')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->timestamp('inventory_restored_at')->nullable();
                                    $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->index(['status', 'reservation_expires_at']);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('transactions', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('invoice_id')->nullable();
            $table->string('payment_method', 30)->nullable();
            $table->string('status', 30)->default('pending');
            $table->decimal('amount', 10, 2)->nullable();
            $table->string('currency', 3)->default('EGP');
            $table->json('gateway_response')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        Schema::create('governorates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('status')->default(true);
            $table->timestamps();
        });

        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('series', 10)->default('INV');
            $table->unsignedInteger('sequence_year');
            $table->unsignedBigInteger('last_sequence')->default(0);
            $table->timestamps();
            $table->unique(['series', 'sequence_year']);
        });

        Schema::create('invoice_timeline', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('event')->nullable();
            $table->string('old_status')->nullable();
            $table->string('new_status')->nullable();
            $table->string('actor_type')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->json('metadata')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->timestamps();
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->nullable()->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->unsignedBigInteger('transaction_id')->nullable();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('invoice_number')->unique();
            $table->string('invoice_series')->nullable();
            $table->unsignedBigInteger('sequence_number')->nullable();
            $table->unsignedInteger('sequence_year')->nullable();
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('shipping_price', 10, 2)->default(0);
            $table->decimal('coupon_discount', 10, 2)->default(0);
            $table->decimal('promotion_discount', 10, 2)->default(0);
            $table->decimal('total_discount', 10, 2)->default(0);
            $table->decimal('total', 10, 2)->default(0);
            $table->decimal('amount_paid', 10, 2)->default(0);
            $table->string('currency', 3)->default('EGP');
            $table->string('payment_method')->nullable();
            $table->string('payment_gateway')->nullable();
            $table->string('status')->default('generated');
            $table->json('data')->nullable();
            $table->string('snapshot_hash')->nullable();
            $table->string('verification_hash')->nullable();
            $table->timestamp('generated_at')->nullable();
            $table->string('generated_by')->nullable();
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
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->integer('quantity')->default(1);
            $table->integer('reserved_quantity')->default(0);
            $table->decimal('price', 10, 2)->default(0);
            $table->decimal('total_price', 10, 2)->default(0);
            $table->text('attributes')->nullable();
            $table->decimal('discount_amount', 10, 2)->default(0);
            $table->string('shipping_method')->default('SCHEDULED');
            $table->boolean('is_gift')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
            $table->timestamps();
        });

        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('slug')->nullable()->unique();
            $table->text('description')->nullable();
            $table->string('sku')->nullable();
            $table->decimal('price', 10, 2)->default(0);
            $table->boolean('status')->default(true);
            $table->boolean('in_stock')->default(true);
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('has_discount')->default(false);
            $table->boolean('has_flash_sale')->default(false);
            $table->string('product_type')->default('simple');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('product_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('sku')->nullable();
            $table->integer('stock_quantity')->default(0);
            $table->integer('reserved_quantity')->default(0);
            $table->integer('sold_quantity')->default(0);
            $table->boolean('in_stock')->default(true);
            $table->timestamps();
        });

        Schema::create('order_products', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products')->nullOnDelete();
            $table->unsignedBigInteger('product_variant_id')->nullable();
            $table->string('product_name')->nullable();
            $table->string('product_sku')->nullable();
            $table->text('attributes')->nullable();
            $table->integer('product_quantity')->default(1);
            $table->decimal('product_price', 10, 2)->default(0);
            $table->decimal('product_total_price', 10, 2)->default(0);
            $table->decimal('product_discount_price', 10, 2)->nullable();
            $table->decimal('product_flash_sale_price', 10, 2)->nullable();
            $table->decimal('promotion_discount_amount', 10, 2)->default(0);
            $table->boolean('is_gift')->default(false);
            $table->unsignedBigInteger('promotion_id')->nullable();
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
        $this->customer = User::create([
            'name' => 'Lifecycle Customer',
            'email' => 'lifecycle@test.com',
            'password' => bcrypt('pass'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function createOrder(string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 130.00,
            'shipping_price' => 30.00,
            'status' => $status,
        ]);
    }

    private function createTransaction(Order $order, string $method = 'cod', string $status = 'pending'): Transaction
    {
        return Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'payment_method' => $method,
            'status' => $status,
            'amount' => $order->total_price,
            'currency' => 'EGP',
            'invoice_id' => 'INV-LC-' . Str::random(8),
            'uuid' => (string) Str::uuid(),
        ]);
    }

    // ===================== DELIVERED EVENT CONTRACT =====================

    /** @test */
    public function completing_to_delivered_dispatches_order_delivered_once()
    {
        Event::fake([OrderStatusChanged::class, OrderDelivered::class]);

        $order = $this->createOrder('completed');
        $service = app(OrderService::class);

        $service->changeOrderStatus(null, 'delivered', $order->id);

        Event::assertDispatchedTimes(OrderDelivered::class, 1);
        Event::assertDispatched(OrderDelivered::class, fn ($e) => $e->order->id === $order->id);
        Event::assertDispatched(OrderStatusChanged::class);
    }

    /** @test */
    public function re_delivering_same_order_does_not_duplicate_delivered_event()
    {
        Event::fake([OrderDelivered::class]);

        $order = $this->createOrder('delivered');
        $service = app(OrderService::class);

        // delivered is terminal for others, but same-status re-set is allowed by matrix
        $service->changeOrderStatus(null, 'delivered', $order->id);
        $service->changeOrderStatus(null, 'delivered', $order->id);

        Event::assertDispatchedTimes(OrderDelivered::class, 0);
    }

    // ===================== COMPLETED => PAYMENT SUCCESS CONTRACT =====================

    /** @test */
    public function admin_completion_via_canonical_transition_emits_payment_succeeded_once()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);

        $order = $this->createOrder('processing');
        $service = app(OrderService::class);

        $result = $service->changeOrderStatus(null, 'completed', $order->id);

        $this->assertNotNull($result);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertDispatched(OrderStatusChanged::class);

        $order->refresh();
        $this->assertEquals('payment-success', $order->payment_status);
        $this->assertNotNull($order->paid_at);
        $this->assertNotNull($order->completed_at);
    }

    /** @test */
    public function gateway_callback_path_does_not_emit_payment_succeeded_from_transition()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);

        $order = $this->createOrder('pending');
        $tx = $this->createTransaction($order, 'online');
        $service = app(OrderService::class);

        // The gateway callback owns the PaymentSucceeded dispatch (fires it
        // itself after commit), so it opts out via $emitPaymentSuccess = false.
        $service->changeOrderStatus($tx->invoice_id, 'completed', null, false);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 0);
        Event::assertDispatched(OrderStatusChanged::class, 1);
    }

    // ===================== COD / CASHIER CANONICAL LIFECYCLE =====================

    /** @test */
    public function mark_cod_as_paid_fires_order_status_changed_and_single_payment_succeeded()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class, OrderCancelled::class]);

        $order = $this->createOrder('pending');
        $this->createTransaction($order, 'cod');
        $service = app(OrderService::class);

        $service->markCodAsPaid($order);

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals('payment-success', $order->payment_status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        Event::assertNotDispatched(OrderCancelled::class);
    }

    /** @test */
    public function mark_cashier_as_paid_fires_order_status_changed_and_single_payment_succeeded()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);

        $order = $this->createOrder('pending');
        $this->createTransaction($order, 'pay_at_cashier');
        $service = app(OrderService::class);

        $service->markCashierPaid($order);

        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals('payment-success', $order->payment_status);

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
    }

    // ===================== CANCELLATION CONTRACT (unchanged guarantees) =====================

    /** @test */
    public function cancellation_still_dispatches_both_events()
    {
        Event::fake([OrderStatusChanged::class, OrderCancelled::class, PaymentSucceeded::class]);

        $order = $this->createOrder('pending');
        $service = app(OrderService::class);

        $service->changeOrderStatus(null, 'cancelled', $order->id);

        Event::assertDispatched(OrderStatusChanged::class);
        Event::assertDispatched(OrderCancelled::class);
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    // ===================== PICKUP NULL-SAFETY ON STATUS CHANGES =====================

    /** @test */
    public function status_transitions_work_for_pickup_order_without_delivery_address()
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'price' => 100.00,
            'total_price' => 100.00,
            'shipping_price' => 0,
            'status' => 'pending',
            'fulfillment_type' => 'pickup',
            'address' => null,
            'pickup_location_id' => null,
        ]);

        $service = app(OrderService::class);

        $processing = $service->changeOrderStatus(null, 'processing', $order->id);
        $this->assertEquals('processing', $processing->refresh()->status);

        $cancelled = $service->changeOrderStatus(null, 'cancelled', $order->id);
        $this->assertEquals('cancelled', $cancelled->refresh()->status);
        $this->assertNotNull($cancelled->cancelled_at);
    }

    // ===================== INVOICE: FIRST LEAVE-PENDING CONTRACT =====================

    /** @test */
    public function pending_to_processing_creates_invoice_once_without_payment_success()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);

        $order = $this->createOrder('pending');
        $service = app(OrderService::class);

        $result = $service->changeOrderStatus(null, 'processing', $order->id);

        $this->assertEquals('processing', $result->status);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function pending_to_completed_creates_exactly_one_invoice_and_one_payment_success()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrder('pending');
        $service = app(OrderService::class);

        $service->changeOrderStatus(null, 'completed', $order->id);

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function pending_to_cancelled_creates_invoice_without_payment_success()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrder('pending');
        $service = app(OrderService::class);

        $service->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        Event::assertNotDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function later_transitions_never_duplicate_the_invoice()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrder('pending');
        $service = app(OrderService::class);

        $service->changeOrderStatus(null, 'processing', $order->id);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        $service->changeOrderStatus(null, 'completed', $order->id);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        $service->changeOrderStatus(null, 'delivered', $order->id);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());

        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function same_status_reassignment_does_not_create_invoice()
    {
        $order = $this->createOrder('pending');
        $service = app(OrderService::class);

        $service->changeOrderStatus(null, 'pending', $order->id);
        $service->changeOrderStatus(null, 'pending', $order->id);

        $this->assertSame(0, Invoice::where('order_id', $order->id)->count());
        $this->assertEquals('pending', $order->refresh()->status);
    }

    /** @test */
    public function mark_cod_produces_exactly_one_invoice_and_one_payment_succeeded()
    {
        Event::fake([PaymentSucceeded::class, OrderStatusChanged::class]);

        $order = $this->createOrder('pending');
        $this->createTransaction($order, 'cod');
        $service = app(OrderService::class);

        $service->markCodAsPaid($order);

        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
        $this->assertEquals('completed', $order->refresh()->status);
        Event::assertDispatchedTimes(PaymentSucceeded::class, 1);
    }

    /** @test */
    public function gateway_opt_out_path_still_creates_the_invoice_from_the_transition()
    {
        Event::fake([PaymentSucceeded::class]);

        $order = $this->createOrder('pending');
        $tx = $this->createTransaction($order, 'online');
        $service = app(OrderService::class);

        // Callback owns PaymentSucceeded (emit=false) ÃƒÆ’Ã†â€™Ãƒâ€šÃ‚Â¢ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â€šÂ¬Ã…Â¡Ãƒâ€šÃ‚Â¬ÃƒÆ’Ã‚Â¢ÃƒÂ¢Ã¢â‚¬Å¡Ã‚Â¬Ãƒâ€šÃ‚Â the invoice must still
        // be created by the first-leave-pending rule inside the transition.
        $service->changeOrderStatus($tx->invoice_id, 'completed', null, false);

        Event::assertNotDispatched(PaymentSucceeded::class);
        $this->assertSame(1, Invoice::where('order_id', $order->id)->count());
    }
}
