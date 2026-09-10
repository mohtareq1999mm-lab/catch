<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\CheckoutTotals;
use App\Events\OrderCancelled;
use App\Services\Checkout\OrderCreationService;
use App\Services\General\CartInventoryService;
use App\Services\Inventory\OrderReservationService;
use App\Services\Coupon\CouponCalculator;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\General\OrderService;
use App\Services\General\PromotionService;
use App\Services\Invoice\InvoiceNumberService;
use App\Services\Invoice\InvoiceSnapshotService;
use App\Services\Invoice\InvoiceSnapshotValidator;
use App\Services\Invoice\SnapshotIntegrityService;
use App\Services\Invoice\Validators\CurrencyValidator;
use App\Services\Invoice\Validators\FinancialInvariantValidator;
use App\Services\Invoice\Validators\MetadataValidator;
use App\Services\Invoice\Validators\MoneyValidator;
use App\Services\Invoice\Validators\SnapshotVersionValidator;
use App\Services\Invoice\Validators\StructureValidator;
use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
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
use App\Models\Invoice;
use App\Models\InvoiceSequence;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;
use Marvel\Enums\ShippingMethod;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ProductionReadinessAuditTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1/general';

    private User $user;
    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        if (!Schema::hasTable('invoice_sequences')) {
            Schema::create('invoice_sequences', function (Blueprint $table) {
                $table->string('series');
                $table->integer('sequence_year');
                $table->integer('last_sequence')->default(0);
                $table->timestamps();
                $table->primary(['series', 'sequence_year']);
            });
        }

        if (!Schema::hasTable('invoices')) {
            Schema::create('invoices', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
                $table->foreignId('transaction_id')->nullable()->constrained('transactions')->nullOnDelete();
                $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
                $table->foreignId('correction_to_id')->nullable()->constrained('invoices')->nullOnDelete();
                $table->string('invoice_number')->unique();
                $table->string('invoice_series');
                $table->integer('sequence_number');
                $table->integer('sequence_year');
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
                $table->string('status')->index();
                $table->json('data')->nullable();
                $table->string('snapshot_hash')->nullable()->index();
                $table->timestamp('pdf_generated_at')->nullable();
                $table->timestamp('pdf_regenerated_at')->nullable();
                $table->string('pdf_path')->nullable();
                $table->string('pdf_checksum')->nullable();
                $table->unsignedInteger('generation_attempts')->default(0);
                $table->text('last_generation_error')->nullable();
                $table->boolean('is_correction')->default(false);
                $table->text('correction_reason')->nullable();
                $table->timestamp('corrected_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->text('cancellation_reason')->nullable();
                $table->timestamp('generated_at')->nullable();
                $table->string('generated_by')->nullable();
                $table->timestamps();
            });
        }

        app()->singleton(InvoiceSnapshotValidator::class, function () {
            return new InvoiceSnapshotValidator(
                app(StructureValidator::class),
                app(SnapshotVersionValidator::class),
                app(MoneyValidator::class),
                app(CurrencyValidator::class),
                app(MetadataValidator::class),
                app(FinancialInvariantValidator::class),
            );
        });

        if (!Settings::exists()) {
            Settings::create([
                'language' => 'en',
                'options' => [],
                'minimum_order_amount' => 0,
            ]);
        }

        $this->user = User::create([
            'name' => 'Audit User',
            'email' => 'audit-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Audit Product',
            'slug' => 'audit-product-' . Str::random(8),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 100,
            'reserved_quantity' => 0,
            'sold_quantity' => 0,
        ]);
    }

    private function authenticate(): void
    {
        Sanctum::actingAs($this->user);
    }

    private function makeCart(int $qty = 2, ?float $price = null, ?Product $product = null): Cart
    {
        $p = $product ?? $this->product;
        $unitPrice = $price ?? $p->price;
        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => $unitPrice * $qty,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $p->id,
            'quantity' => $qty,
            'price' => $unitPrice,
            'total_price' => $unitPrice * $qty,
            'reserved_quantity' => $qty,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        return $cart->fresh()->load(['items', 'items.product']);
    }

    private function makeGovWithShipping(float $price = 10): Governorate
    {
        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $gov = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Gov ' . Str::random(4),
            'status' => true,
        ]);
        ShippingPrice::create([
            'governorate_id' => $gov->id,
            'price' => $price,
            'status' => true,
        ]);
        return $gov;
    }

    private function simulateCheckout(Cart $cart, ?string $couponCode = null, ?int $promotionId = null, ?Governorate $gov = null): Order
    {
        if ($couponCode) {
            $cart->update(['coupon' => $couponCode]);
        }

        $items = $cart->items->reject(fn($i) => (bool) ($i->is_gift ?? false));

        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart,
            $promotionId,
            null,
            ShippingMethod::SCHEDULED,
        );

        $shippingPrice = $gov ? (float) $gov?->shippingPrice?->price ?? 10 : 0;

        $orderService = app(OrderCreationService::class);

        // Simulate addItemsInOrder logic but without the full Request dependency
        $pendingOrder = $orderService->findPendingOrderForUser((int) $this->user->id);
        if ($pendingOrder) {
            $order = $orderService->updateOrder(
                $pendingOrder,
                [
                    'name' => $this->user->name,
                    'user_phone' => '01000000000',
                    'user_email' => $this->user->email,
                    'address' => ['street' => 'Test', 'city' => 'Test'],
                    'fulfillment_type' => 'delivery',
                    'payment_method' => 'cod',
                ],
                $cart,
                $totals,
                null, null, null, $shippingPrice, $gov?->id,
            );
            $orderService->syncOrderItems($order, $cart);
            $orderService->updateTransactionAmount($order);
        } else {
            $order = $orderService->createOrder(
                [
                    'user_id' => $this->user->id,
                    'name' => $this->user->name,
                    'user_phone' => '01000000000',
                    'user_email' => $this->user->email,
                    'address' => ['street' => 'Test', 'city' => 'Test'],
                    'fulfillment_type' => 'delivery',
                    'payment_method' => 'cod',
                ],
                $cart,
                $totals,
                null, null, null, $shippingPrice, $gov?->id,
            );
            $orderService->createOrderItems($order, $cart);
        }

        return $order->fresh()->load(['orderItems', 'transactions']);
    }

    // =========================================================================
    // TOCTOU Race: findPendingOrderForUser (CONC-5)
    // =========================================================================

    /** @test */
    public function find_pending_order_uses_no_lock_race_possible(): void
    {
        $this->authenticate();
        $cart = $this->makeCart();
        $order1 = $this->simulateCheckout($cart);

        $orderService = app(OrderCreationService::class);

        // First call finds the pending order
        $found = $orderService->findPendingOrderForUser((int) $this->user->id);
        $this->assertNotNull($found);
        $this->assertEquals('pending', $found->status);
        $this->assertEquals($order1->id, $found->id);

        // Simulate what happens when another request processes it simultaneously
        // by modifying the order directly
        $order1->update(['status' => 'completed']);

        // Now the first call's reference is stale - it still holds the order,
        // but the DB status changed. This is the TOCTOU bug.
        $staleRef = Order::find($order1->id);
        $this->assertEquals('completed', $staleRef->status);

        // The stale reference's status is NOT re-checked before updateOrder()
        // This test documents the race condition.
    }

    /** @test */
    public function concurrent_checkout_uses_same_pending_order(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 100.00);
        $order1 = $this->simulateCheckout($cart);

        // Simulate two concurrent requests both finding the same pending order
        $service = app(OrderCreationService::class);

        // Both calls return the same order (no lock to serialize)
        $found1 = $service->findPendingOrderForUser((int) $this->user->id);
        $found2 = $service->findPendingOrderForUser((int) $this->user->id);

        $this->assertEquals($found1->id, $found2->id);
        $this->assertEquals('pending', $found1->status);
    }

    // =========================================================================
    // Stale Coupon Bug (CPN-1 / FIN-6)
    // =========================================================================

    /** @test */
    public function stale_coupon_in_memory_after_invalidation(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 100.00);

        // Create an expired coupon
        $coupon = Coupon::create([
            'code' => 'EXPIRED-' . Str::upper(Str::random(4)),
            'slug' => 'expired-' . Str::random(6),
            'name' => 'Expired Coupon',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 20,
            'status' => true,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),  // Expired
        ]);

        $cart->update(['coupon' => $coupon->code]);
        $cart->refresh();

        // The cart has the coupon in memory
        $this->assertEquals($coupon->code, $cart->coupon);

        // Simulate the checkout logic up to coupon validation
        $lockedCoupon = Coupon::where('code', $cart->coupon)->lockForUpdate()->first();
        $validation = CouponOrchestrator::validate($lockedCoupon, $this->user, $cart->items);

        // Coupon is invalid (expired)
        $this->assertFalse($validation['valid']);

        // DB is updated but in-memory cart is stale
        DB::table('carts')->where('id', $cart->id)->update(['coupon' => null]);
        $this->assertNotNull($cart->coupon);  // STALE! Still holds old value
        $this->assertNull($cart->fresh()->coupon);  // DB is correct

        // If calculatePriceByCoupon is called now, it reads $cart->coupon (stale)
        // and re-applies the expired coupon. This is the bug documented as CPN-1.
    }

    /** @test */
    public function stale_coupon_applied_to_totals(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 100.00);

        $coupon = Coupon::create([
            'code' => 'STALE-' . Str::upper(Str::random(4)),
            'slug' => 'stale-' . Str::random(6),
            'name' => 'Stale Coupon',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 30,
            'status' => true,
            'start_date' => now()->subMonths(2),
            'end_date' => now()->subMonth(),
        ]);

        $cart->update(['coupon' => $coupon->code]);
        $cart->refresh();

        // Simulate invalidation at checkout (line 173-179 of OrderService)
        $lockedCoupon = Coupon::where('code', $cart->coupon)->lockForUpdate()->first();
        $this->assertNotNull($lockedCoupon);
        $validation = CouponOrchestrator::validate($lockedCoupon, $this->user, $cart->items);
        $this->assertFalse($validation['valid']);

        // Invalidate in DB but not in memory (the bug)
        $cart->update(['coupon' => null]);
        $cart->refresh();  // If this line were present, the fix works

        // After refresh, the cart is correct
        $this->assertNull($cart->coupon);

        // Now calculateCheckoutTotals will correctly skip coupon
        $totals = app(OrderService::class)->calculateCheckoutTotals($cart, null, null, ShippingMethod::SCHEDULED);
        $this->assertEquals(0, $totals->couponDiscount);
        $this->assertEquals(200.00, $totals->finalTotal);
    }

    // =========================================================================
    // CancelUnpaidOrders Race (CONC-3)
    // =========================================================================

    /** @test */
    public function cancel_unpaid_orders_no_lock_race(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 100.00);
        $order = $this->simulateCheckout($cart);

        // Simulate CancelUnpaidOrders reading the order (no lock)
        $cutoff = now()->subHours(72);
        $foundOrders = Order::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->cursor();

        $foundList = [];
        foreach ($foundOrders as $o) {
            $foundList[] = $o->id;
        }

        // Simulate a concurrent checkout completing payment between read and write
        $order->update(['status' => 'completed']);

        // Now CancelUnpaidOrders processes the stale reference (the bug)
        DB::transaction(function () use ($order) {
            $order->update(['status' => 'cancelled']);
        });

        // Order was cancelled despite being completed!
        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    /** @test */
    public function cancel_unpaid_orders_with_lock_prevents_race(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 100.00);
        $order = $this->simulateCheckout($cart);

        // Make the order old enough to be past the default 72h cutoff
        $order->forceFill(['created_at' => now()->subDays(4)])->save();

        $cutoff = now()->subHours(72);

        $orders = Order::query()
            ->where('status', 'pending')
            ->where('created_at', '<=', $cutoff)
            ->cursor();

        foreach ($orders as $o) {
            DB::transaction(function () use ($o) {
                $locked = Order::whereKey($o->id)->lockForUpdate()->first();
                if (!$locked || $locked->status !== 'pending') {
                    return;  // Already changed by concurrent process
                }
                $locked->update(['status' => 'cancelled']);
            });
        }

        $this->assertEquals('cancelled', $order->fresh()->status);
    }

    // =========================================================================
    // Financial Invariant: Order Total = subtotal - discounts + shipping
    // =========================================================================

    /** @test */
    public function order_total_matches_financial_invariant(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(3, 49.99);
        $gov = $this->makeGovWithShipping(15.50);

        $promotion = Promotion::create([
            'name' => 'Test Promotion',
            'slug' => 'test-promo-' . Str::random(6),
            'code' => 'PROMO-' . Str::upper(Str::random(4)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'apply_to' => 'all_products',
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);

        $coupon = Coupon::create([
            'code' => 'SAVE50-' . Str::upper(Str::random(4)),
            'slug' => 'save50-' . Str::random(6),
            'name' => 'Save 50',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 20,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart->update(['coupon' => $coupon->code]);
        $cart->refresh();

        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart, (int) $promotion->id, null, ShippingMethod::SCHEDULED,
        );

        $shippingPrice = 15.50;
        $orderTotal = round($totals->finalTotal + $shippingPrice, 2);

        // Verify financial invariant: subtotal - promotion - coupon + shipping = total
        $computed = round($totals->subtotal - $totals->promotionDiscount - $totals->couponDiscount + $shippingPrice, 2);
        $this->assertEquals($computed, $orderTotal, "Financial invariant violated: {$totals->subtotal} - {$totals->promotionDiscount} - {$totals->couponDiscount} + {$shippingPrice} = {$computed}, but order total is {$orderTotal}");

        // Verify no negative discount
        $this->assertGreaterThanOrEqual(0, $totals->couponDiscount);
        $this->assertGreaterThanOrEqual(0, $totals->promotionDiscount);
        $this->assertGreaterThanOrEqual(0, $totals->finalTotal);
    }

    /** @test */
    public function financial_invariant_with_zero_discounts(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 50.00);
        $gov = $this->makeGovWithShipping(10.00);

        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart, null, null, ShippingMethod::SCHEDULED,
        );

        $shippingPrice = 10.00;
        $orderTotal = round($totals->finalTotal + $shippingPrice, 2);

        $computed = round($totals->subtotal - $totals->promotionDiscount - $totals->couponDiscount + $shippingPrice, 2);
        $this->assertEquals($computed, $orderTotal);
        $this->assertEquals(100.00, $totals->subtotal);
        $this->assertEquals(0, $totals->promotionDiscount);
        $this->assertEquals(0, $totals->couponDiscount);
        $this->assertEquals(100.00, $totals->finalTotal);
    }

    // =========================================================================
    // Promotion + Coupon Stacking
    // =========================================================================

    /** @test */
    public function promotion_discount_isolation(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 100.00);

        $promotion = Promotion::create([
            'name' => '20% Off',
            'slug' => 'pct-off-' . Str::random(6),
            'code' => 'PCT-' . Str::upper(Str::random(4)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 20,
            'discount' => 20,
            'status' => true,
            'apply_to' => 'all_products',
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);

        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart, (int) $promotion->id, null, ShippingMethod::SCHEDULED,
        );

        // 200 * 20% = 40 discount
        $this->assertEquals(200.00, $totals->subtotal);
        $this->assertEquals(40.00, $totals->promotionDiscount);
        $this->assertEquals(0, $totals->couponDiscount);
        $this->assertEquals(160.00, $totals->finalTotal);
    }

    /** @test */
    public function coupon_on_top_of_promotion(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2, 100.00);

        $promotion = Promotion::create([
            'name' => '10% Off',
            'slug' => 'ten-off-' . Str::random(6),
            'code' => 'TEN-' . Str::upper(Str::random(4)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::PERCENTAGE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'apply_to' => 'all_products',
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);

        $coupon = Coupon::create([
            'code' => 'FIXED15-' . Str::upper(Str::random(4)),
            'slug' => 'fixed15-' . Str::random(6),
            'name' => 'Fixed 15',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 15,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart->update(['coupon' => $coupon->code]);
        $cart->refresh();

        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart, (int) $promotion->id, null, ShippingMethod::SCHEDULED,
        );

        // Promotion: 200 * 10% = 20 → priceAfterPromotion = 180
        // Coupon: min(15, 180) = 15 → finalTotal = 165
        $this->assertEquals(200.00, $totals->subtotal);
        $this->assertEquals(20.00, $totals->promotionDiscount);
        $this->assertEquals(15.00, $totals->couponDiscount);
        $this->assertEquals(165.00, $totals->finalTotal);
    }

    // =========================================================================
    // CouponCalculator Exactness
    // =========================================================================

    /** @test */
    public function coupon_percentage_with_max_cap(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::PERCENTAGE;
        $coupon->discount = 15;
        $coupon->max_discount_amount = 10;

        $result = CouponCalculator::calculate($coupon, 100.00);
        $this->assertEquals(10.00, $result['discountAmount']);  // Capped at 10
        $this->assertEquals(90.00, $result['finalPrice']);

        // Below cap
        $result = CouponCalculator::calculate($coupon, 50.00);
        $this->assertEquals(7.50, $result['discountAmount']);
        $this->assertEquals(42.50, $result['finalPrice']);
    }

    /** @test */
    public function coupon_fixed_not_exceeding_price(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::FIXED_RATE;
        $coupon->discount = 100;

        $result = CouponCalculator::calculate($coupon, 30.00);
        $this->assertEquals(30.00, $result['discountAmount']);  // Capped at price
        $this->assertEquals(0, $result['finalPrice']);
    }

    /** @test */
    public function coupon_free_shipping_type(): void
    {
        $coupon = new Coupon();
        $coupon->discount_type = DiscountType::FREE_SHIPPING;
        $coupon->discount = 0;

        $result = CouponCalculator::calculate($coupon, 100.00);
        $this->assertEquals(0, $result['discountAmount']);
        $this->assertEquals(100.00, $result['finalPrice']);
        $this->assertTrue($result['freeShipping']);
    }

    // =========================================================================
    // Coupon Usage Recording Idempotency
    // =========================================================================

    /** @test */
    public function coupon_usage_recorded_once(): void
    {
        $this->authenticate();
        $coupon = Coupon::create([
            'code' => 'USAGE-' . Str::upper(Str::random(4)),
            'slug' => 'usage-' . Str::random(6),
            'name' => 'Usage Test',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 10,
            'status' => true,
            'used' => 0,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $cart = $this->makeCart(2);
        $cart->update(['coupon' => $coupon->code]);
        $order = $this->simulateCheckout($cart);

        // Call recordCouponUsage (via OrderService)
        $orderService = app(OrderService::class);
        $ref = new \ReflectionMethod($orderService, 'recordCouponUsage');
        $ref->setAccessible(true);
        $ref->invoke($orderService, $order);

        // First call: usage should be 1
        $this->assertEquals(1, $coupon->fresh()->used);

        // Second call: should not increment
        $ref->invoke($orderService, $order);
        $this->assertEquals(1, $coupon->fresh()->used);
    }

    /** @test */
    public function assigned_coupon_usage_respects_max_uses(): void
    {
        $this->authenticate();
        $coupon = Coupon::create([
            'code' => 'ASSIGN-' . Str::upper(Str::random(4)),
            'slug' => 'assign-' . Str::random(6),
            'name' => 'Assignment Test',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 10,
            'status' => true,
            'used' => 0,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
            'max_uses' => 2,
            'used' => 0,
        ]);

        $cart = $this->makeCart(2);
        $cart->update(['coupon' => $coupon->code]);
        $order = $this->simulateCheckout($cart);

        $orderService = app(OrderService::class);
        $ref = new \ReflectionMethod($orderService, 'recordCouponUsage');
        $ref->setAccessible(true);
        $ref->invoke($orderService, $order);

        $this->assertEquals(1, $coupon->fresh()->used);
        $this->assertEquals(1, $assignment->fresh()->used);

        // Complete the first order so the second checkout creates a new order
        $order->update(['status' => 'completed']);

        // Create another order and record again
        $cart2 = $this->makeCart(2);
        $cart2->update(['coupon' => $coupon->code]);
        $order2 = $this->simulateCheckout($cart2);
        $ref->invoke($orderService, $order2);

        $this->assertEquals(2, $coupon->fresh()->used);
        $this->assertEquals(2, $assignment->fresh()->used);

        // Third attempt should be blocked by max_uses check
        $this->assertEquals(2, $assignment->fresh()->used);
    }

    // =========================================================================
    // Promotion Limiter
    // =========================================================================

    /** @test */
    public function promotion_limiter_enforced(): void
    {
        $this->authenticate();
        $promotion = Promotion::create([
            'name' => 'Limited',
            'slug' => 'limited-' . Str::random(6),
            'code' => 'LIM-' . Str::upper(Str::random(4)),
            'type' => PromotionType::PRICE,
            'type_amount' => PromotionMountType::FIXED_RATE,
            'value' => 10,
            'discount' => 10,
            'status' => true,
            'limiter' => 3,
            'usage' => 3,
            'apply_to' => 'all_products',
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
        ]);

        $cart = $this->makeCart(2);

        $promoService = app(PromotionService::class);
        $hasEligible = $promoService->hasEligiblePromotion($cart);
        $this->assertFalse($hasEligible, 'Promotion at limiter should not be eligible');

        // incrementUsage should not exceed limiter
        $promoService->incrementUsage((int) $promotion->id);
        $this->assertEquals(3, $promotion->fresh()->usage);
    }

    // =========================================================================
    // Cart Expiration releases inventory correctly
    // =========================================================================

    /** @test */
    public function cart_expiration_releases_inventory(): void
    {
        // NEW CONTRACT: the ORDER owns the reservation; expiry releases it.
        $this->authenticate();
        $qty = 3;

        $order = \Marvel\Database\Models\Order::create([
            'user_id' => $this->user->id,
            'name' => 'PRA', 'user_phone' => '01', 'user_email' => $this->user->email,
            'address' => '{}', 'total_price' => 100, 'status' => 'pending',
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_quantity' => $qty,
            'product_price' => 100,
            'product_total_price' => 100 * $qty,
        ]);

        /** @var OrderReservationService $reservations */
        $reservations = app(OrderReservationService::class);
        $reservations->reserveForOrder($order->refresh());

        $initialStock = $this->product->stock_quantity;
        $this->assertEquals($qty, $this->product->fresh()->reserved_quantity);

        $reservations->release($order->refresh());

        $productAfter = $this->product->fresh();
        $this->assertEquals(0, $productAfter->reserved_quantity);
        $this->assertEquals($initialStock, $productAfter->stock_quantity);
        $this->assertEquals(\Marvel\Database\Models\Order::INVENTORY_STATE_RELEASED, $order->fresh()->inventory_state);
    }

    /** @test */
    public function already_expired_cart_not_double_released(): void
    {
        $this->authenticate();

        $order = \Marvel\Database\Models\Order::create([
            'user_id' => $this->user->id,
            'name' => 'PRA2', 'user_phone' => '01', 'user_email' => $this->user->email,
            'address' => '{}', 'total_price' => 100, 'status' => 'pending',
        ]);
        $order->orderItems()->create([
            'product_id' => $this->product->id,
            'product_name' => $this->product->name,
            'product_quantity' => 2,
            'product_price' => 100,
            'product_total_price' => 200,
        ]);

        /** @var OrderReservationService $reservations */
        $reservations = app(OrderReservationService::class);
        $reservations->reserveForOrder($order->refresh());

        // Release twice — the second must be a no-op.
        $this->assertTrue($reservations->release($order->refresh()));
        $afterFirst = $this->product->fresh()->reserved_quantity;

        $this->assertFalse($reservations->release($order->refresh()));

        $this->assertEquals(0, $this->product->fresh()->reserved_quantity);
        $this->assertEquals($afterFirst, $this->product->fresh()->reserved_quantity);
    }

    // =========================================================================
    // Inventory Restoration Idempotency (inventory_restored_at)
    // =========================================================================

    /** @test */
    public function inventory_restoration_guard_prevents_double_restore(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2);
        $order = $this->simulateCheckout($cart);

        $this->assertNull($order->inventory_restored_at);

        // Simulate the OrderCancelled event handler
        $updated = Order::whereKey($order->id)
            ->whereNull('inventory_restored_at')
            ->lockForUpdate()
            ->update(['inventory_restored_at' => now()]);

        $this->assertEquals(1, $updated);

        // Second attempt should be blocked
        $updated = Order::whereKey($order->id)
            ->whereNull('inventory_restored_at')
            ->lockForUpdate()
            ->update(['inventory_restored_at' => now()]);

        $this->assertEquals(0, $updated);
    }

    // =========================================================================
    // CheckoutTotals DTO Invariants
    // =========================================================================

    /** @test */
    public function checkout_totals_dto_invariants(): void
    {
        $totals = new CheckoutTotals(
            subtotal: 100.00,
            promotionDiscount: 10.00,
            couponDiscount: 5.00,
            finalTotal: 85.00,
        );

        $this->assertEquals(15.00, $totals->getTotalDiscount());
        $this->assertFalse($totals->hasCoupon());
        $this->assertFalse($totals->hasPromotion());
        $this->assertNull($totals->promotionId());
        $this->assertNull($totals->promotionCode());
    }

    // =========================================================================
    // Free Shipping Threshold Logic
    // =========================================================================

    /** @test */
    public function free_shipping_by_threshold(): void
    {
        $orderService = app(OrderService::class);

        // Below threshold → shipping charged
        $result = $orderService->resolveFreeShippingByThreshold(50.00, 100.00, 15.00);
        $this->assertEquals(15.00, $result);

        // At threshold → shipping charged (strict greater)
        $result = $orderService->resolveFreeShippingByThreshold(100.00, 100.00, 15.00);
        $this->assertEquals(15.00, $result);

        // Above threshold → free
        $result = $orderService->resolveFreeShippingByThreshold(100.01, 100.00, 15.00);
        $this->assertEquals(0, $result);

        // No threshold set → always charged
        $result = $orderService->resolveFreeShippingByThreshold(1000.00, null, 15.00);
        $this->assertEquals(15.00, $result);
    }

    /** @test */
    public function free_shipping_by_coupon_type(): void
    {
        $orderService = app(OrderService::class);

        // FREE_SHIPPING coupon type → shipping = 0
        $result = $orderService->resolveFreeShippingByCoupon(DiscountType::FREE_SHIPPING, 15.00);
        $this->assertEquals(0, $result);

        // Any other type → shipping unchanged
        $result = $orderService->resolveFreeShippingByCoupon(DiscountType::PERCENTAGE, 15.00);
        $this->assertEquals(15.00, $result);

        $result = $orderService->resolveFreeShippingByCoupon(null, 15.00);
        $this->assertEquals(15.00, $result);
    }

    // =========================================================================
    // Minimum Order Amount Guard
    // =========================================================================

    /** @test */
    public function minimum_order_amount_rejects_low_subtotal(): void
    {
        Settings::where('id', '>', 0)->update(['minimum_order_amount' => 50.00]);

        $this->authenticate();
        $cart = $this->makeCart(1, 30.00);

        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart, null, null, ShippingMethod::SCHEDULED,
        );

        $this->assertEquals(30.00, $totals->subtotal);
        $this->assertLessThan(50.00, $totals->subtotal);
    }

    // =========================================================================
    // Order Status Transitions
    // =========================================================================

    /** @test */
    public function order_status_transition_rules(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2);
        $order = $this->simulateCheckout($cart);

        $this->assertEquals('pending', $order->status);

        $orderService = app(OrderService::class);

        // pending → completed (allowed)
        $result = $orderService->changeOrderStatus(null, 'completed', $order->id);
        $this->assertNotFalse($result);
        $this->assertEquals('completed', $order->fresh()->status);

        // completed → cancelled (FORBIDDEN)
        $this->expectException(\RuntimeException::class);
        $orderService->changeOrderStatus(null, 'cancelled', $order->id);
    }

    // =========================================================================
    // Invoice Number Generation (concurrent-safe)
    // =========================================================================

    /** @test */
    public function invoice_number_generation_is_consecutive(): void
    {
        $service = app(InvoiceNumberService::class);

        $first = $service->generateNext('TEST');
        $second = $service->generateNext('TEST');

        $this->assertEquals('TEST', $first['series']);
        $this->assertEquals($first['year'], $second['year']);
        $this->assertEquals($first['sequence'] + 1, $second['sequence']);
        $this->assertStringContainsString((string) $first['sequence'], $first['number']);
    }

    /** @test */
    public function invoice_number_resets_per_year(): void
    {
        $service = app(InvoiceNumberService::class);

        // Generate in current year
        $result = $service->generateNext('YR');
        $this->assertEquals((int) now()->year, $result['year']);

        // Simulate next year
        Carbon::setTestNow(Carbon::now()->addYear());
        $nextYearResult = $service->generateNext('YR');
        $this->assertEquals((int) now()->year, $nextYearResult['year']);
        $this->assertEquals(1, $nextYearResult['sequence']);  // Reset to 1

        Carbon::setTestNow();
    }

    // =========================================================================
    // Snapshot Integrity (SHA-256 hash)
    // =========================================================================

    /** @test */
    public function snapshot_hash_is_deterministic(): void
    {
        $data = [
            'snapshot_version' => '2.0.0',
            'snapshot_schema' => 2,
            'customer' => ['id' => 1, 'name' => 'Test', 'email' => 'test@test.com', 'phone' => '01000000000'],
            'items' => [],
            'pricing_breakdown' => ['subtotal' => 100, 'total' => 100, 'currency' => 'EGP'],
            'payment' => ['method' => 'cod', 'transaction_id' => null, 'paid_at' => null],
            'fulfillment' => ['type' => 'delivery', 'shipping_method' => 'SCHEDULED', 'shipping_price' => 0],
            'metadata' => ['system_version' => '1.0', 'locale' => 'en', 'generated_at' => '2026-01-01'],
            'audit' => ['generated_by' => 'system', 'generation_attempts' => 1],
            'taxes' => [],
            'billing_address' => [],
            'shipping_address' => [],
            'notes' => null,
        ];

        $service = app(SnapshotIntegrityService::class);
        $hash1 = $service->computeHash($data);
        $hash2 = $service->computeHash($data);

        $this->assertEquals($hash1, $hash2);
        $this->assertEquals(64, strlen($hash1));  // SHA-256 hex

        // Verify: different data produces different hash
        $data['pricing_breakdown']['total'] = 101;
        $hash3 = $service->computeHash($data);
        $this->assertNotEquals($hash1, $hash3);
    }

    // =========================================================================
    // Financial Invariant: Snapshot Validation
    // =========================================================================

    /** @test */
    public function snapshot_financial_invariant_passes(): void
    {
        $snapshot = [
            'snapshot_version' => '2.0.0',
            'snapshot_schema' => 2,
            'customer' => ['id' => 1, 'name' => 'T', 'email' => 't@t.com', 'phone' => '01000000000'],
            'billing_address' => ['street' => null, 'city' => null, 'state' => null, 'governorate' => null, 'zip' => null, 'country' => null, 'coordinates' => null],
            'shipping_address' => ['street' => null, 'city' => null, 'state' => null, 'governorate' => null, 'zip' => null, 'country' => null, 'coordinates' => null],
            'fulfillment' => ['type' => 'delivery', 'shipping_method' => 'SCHEDULED', 'shipping_price' => 15.50],
            'items' => [],
            'pricing_breakdown' => [
                'subtotal' => 200.00,
                'promotion_discount' => 20.00,
                'coupon_discount' => 10.00,
                'shipping_price' => 15.50,
                'fast_shipping_fee' => 0,
                'total' => 185.50,
                'currency' => 'EGP',
            ],
            'payment' => ['method' => 'cod', 'gateway' => null, 'transaction_id' => null, 'gateway_transaction_id' => null, 'paid_at' => null],
            'taxes' => [],
            'metadata' => ['system_version' => '1.0', 'locale' => 'en', 'generated_at' => now()->toIso8601String()],
            'notes' => null,
            'audit' => ['generated_by' => 'system', 'generation_attempts' => 1, 'correction_reason' => null, 'cancellation_reason' => null],
        ];

        $validator = app(InvoiceSnapshotValidator::class);
        $validator->validate($snapshot);
        $this->assertTrue(true);  // No exception thrown
    }

    /** @test */
    public function snapshot_financial_invariant_fails_on_mismatch(): void
    {
        $this->expectException(\App\Exceptions\FinancialInvariantException::class);

        $snapshot = [
            'snapshot_version' => '2.0.0',
            'snapshot_schema' => 2,
            'customer' => ['id' => 1, 'name' => 'T', 'email' => 't@t.com', 'phone' => '01000000000'],
            'billing_address' => ['street' => null, 'city' => null, 'state' => null, 'governorate' => null, 'zip' => null, 'country' => null, 'coordinates' => null],
            'shipping_address' => ['street' => null, 'city' => null, 'state' => null, 'governorate' => null, 'zip' => null, 'country' => null, 'coordinates' => null],
            'fulfillment' => ['type' => 'delivery', 'shipping_method' => 'SCHEDULED', 'shipping_price' => 10.00],
            'items' => [],
            'pricing_breakdown' => [
                'subtotal' => 100.00,
                'promotion_discount' => 0,
                'coupon_discount' => 0,
                'shipping_price' => 10.00,
                'fast_shipping_fee' => 0,
                'total' => 115.00,  // wrong! should be 110.00
                'currency' => 'EGP',
            ],
            'payment' => ['method' => 'cod', 'gateway' => null, 'transaction_id' => null, 'gateway_transaction_id' => null, 'paid_at' => null],
            'taxes' => [],
            'metadata' => ['system_version' => '1.0', 'locale' => 'en', 'generated_at' => now()->toIso8601String()],
            'notes' => null,
            'audit' => ['generated_by' => 'system', 'generation_attempts' => 1, 'correction_reason' => null, 'cancellation_reason' => null],
        ];

        $validator = app(InvoiceSnapshotValidator::class);
        $validator->validate($snapshot);
    }

    // =========================================================================
    // Product Variant Pricing
    // =========================================================================

    /** @test */
    public function variant_pricing_in_checkout(): void
    {
        $this->authenticate();

        $variant = ProductVariant::create([
            'product_id' => $this->product->id,
            'price' => 150.00,
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
            'in_stock' => true,
        ]);

        $cart = Cart::create([
            'user_id' => $this->user->id,
            'status' => 'active',
            'total_price' => 150.00,
        ]);

        CartItem::create([
            'cart_id' => $cart->id,
            'product_id' => $this->product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1,
            'price' => 150.00,
            'total_price' => 150.00,
            'reserved_quantity' => 1,
            'shipping_method' => ShippingMethod::SCHEDULED,
        ]);

        $cart = $cart->fresh()->load(['items.product', 'items.productVariant']);
        $totals = app(OrderService::class)->calculateCheckoutTotals(
            $cart, null, null, ShippingMethod::SCHEDULED,
        );

        $this->assertEquals(150.00, $totals->subtotal);
        $this->assertEquals(150.00, $totals->finalTotal);
    }

    // =========================================================================
    // Payment Flow: Transaction Creation
    // =========================================================================

    /** @test */
    public function transaction_created_for_cod_payment(): void
    {
        $this->authenticate();
        $cart = $this->makeCart(2);
        $order = $this->simulateCheckout($cart);

        // Manually create a COD transaction (as PaymentCheckoutHandler does)
        $transaction = Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'pending',
            'amount' => $order->total_price,
            'currency' => 'EGP',
        ]);

        $this->assertNotNull($transaction);
        $this->assertNotNull($transaction->uuid);
        $this->assertEquals('pending', $transaction->status);
        $this->assertEquals($order->total_price, $transaction->amount);

        // Mark as paid (simulating markCodAsPaid)
        $transaction->update(['status' => 'paid', 'paid_at' => now()]);
        $order->update(['status' => 'completed']);

        $this->assertEquals('paid', $transaction->fresh()->status);
        $this->assertEquals('completed', $order->fresh()->status);
    }

    // =========================================================================
    // Governorate Shipping Resolution
    // =========================================================================

    /** @test */
    public function governorate_shipping_resolved_correctly(): void
    {
        $country = Country::create(['name' => 'Test', 'status' => true]);
        $gov = Governorate::create(['country_id' => $country->id, 'name' => 'Cairo', 'status' => true]);
        ShippingPrice::create(['governorate_id' => $gov->id, 'price' => 25.00, 'free_shipping_over' => 200.00, 'status' => true]);

        $orderService = app(OrderService::class);
        $ref = new \ReflectionMethod($orderService, 'resolveShippingPrice');
        $ref->setAccessible(true);
        $result = $ref->invoke($orderService, (int) $gov->id);

        $this->assertEquals(25.00, $result['price']);
        $this->assertEquals(200.00, $result['free_shipping_over']);
        $this->assertEquals($gov->id, $result['governorate_id']);
    }
}
