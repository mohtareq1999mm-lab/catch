<?php

namespace Tests\Feature;

use App\Models\CouponReservation;
use App\Services\Coupon\CouponReservationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Tests\Concerns\CreatesTestTables;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

/**
 * Tests for Business Rules implementation:
 * - Rule 4-5: Pending order reuse
 * - Rule 9-10: Coupon reservation
 * - Rule 17: Paid order cancellation
 */
class BusinessRulesImplementationTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables, WithInvoiceTables;

    private User $user;
    private Product $product;
    private Governorate $governorate;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();
        $this->createInvoiceTables();

        // Create coupon_reservations table
        if (!\Illuminate\Support\Facades\Schema::hasTable('coupon_reservations')) {
            \Illuminate\Support\Facades\Schema::create('coupon_reservations', function ($table) {
                $table->id();
                $table->foreignId('coupon_id')->constrained()->onDelete('cascade');
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->foreignId('order_id')->constrained()->onDelete('cascade');
                $table->timestamp('reserved_at');
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index(['coupon_id', 'expires_at']);
                $table->unique(['order_id']);
            });
        }

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->product = Product::create([
            'name' => 'Test Product',
            'slug' => 'test-product-' . uniqid(),
            'price' => 100.00,
            'product_type' => ProductType::SIMPLE,
            'status' => true,
            'in_stock' => true,
            'stock_quantity' => 50,
        ]);

        $country = Country::create(['name' => 'Test Country', 'status' => true]);
        $this->governorate = Governorate::create([
            'country_id' => $country->id,
            'name' => 'Test Governorate',
            'status' => true,
        ]);
        ShippingPrice::create([
            'governorate_id' => $this->governorate->id,
            'price' => 20,
            'status' => true,
        ]);

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function test_pending_order_reuse_on_second_checkout()
    {
        // First checkout: creates pending order
        $this->addToCart(3);
        $response1 = $this->checkout();
        $response1->assertSuccessful();

        $firstOrder = Order::where('user_id', $this->user->id)->where('status', 'pending')->first();
        $this->assertNotNull($firstOrder);
        $firstOrderId = $firstOrder->id;

        // Cart should be empty after checkout
        $cart = Cart::where('user_id', $this->user->id)->first();
        $this->assertEquals(0, $cart->items()->count());

        // Add items again (simulating retry with cart refill)
        $this->addToCart(5);

        // Second checkout: should REUSE the pending order (Rule 4-5)
        $response2 = $this->checkout();
        $response2->assertSuccessful();

        // Should still have only ONE pending order (not two)
        $pendingOrders = Order::where('user_id', $this->user->id)->where('status', 'pending')->get();
        $this->assertCount(1, $pendingOrders, 'Should reuse pending order, not create duplicate');

        // Should be the same order ID
        $this->assertEquals($firstOrderId, $pendingOrders->first()->id);

        // Order should be updated with new quantity
        $updatedOrder = $pendingOrders->first();
        $this->assertEquals(5, $updatedOrder->orderItems->sum('product_quantity'));
    }

    /** @test */
    public function test_coupon_reservation_prevents_double_booking()
    {
        // Create a single-use coupon
        $coupon = Coupon::create([
            'code' => 'SAVE50',
            'discount_type' => DiscountType::FIXED,
            'discount_amount' => 50,
            'limiter' => 1, // Only 1 use allowed
            'used' => 0,
            'is_valid' => true,
            'status' => 1,
        ]);

        // User 1 checks out with coupon
        $this->addToCart(1);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => 'SAVE50']);

        $response = $this->checkout();
        $response->assertSuccessful();

        // Coupon should be reserved
        $reservation = CouponReservation::where('coupon_id', $coupon->id)->first();
        $this->assertNotNull($reservation, 'Coupon should be reserved during payment window');

        // User 2 tries to use the same coupon (should fail)
        $user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
        Sanctum::actingAs($user2);

        $this->addToCart(1);
        $cart2 = Cart::where('user_id', $user2->id)->first();
        $cart2->update(['coupon' => 'SAVE50']);

        $response2 = $this->checkout();

        // Should fail because coupon is reserved
        $response2->assertStatus(422);
    }

    /** @test */
    public function test_coupon_reservation_consumed_on_payment_success()
    {
        $coupon = Coupon::create([
            'code' => 'TEST10',
            'discount_type' => DiscountType::FIXED,
            'discount_amount' => 10,
            'limiter' => 10,
            'used' => 0,
            'is_valid' => true,
            'status' => 1,
        ]);

        $this->addToCart(1);
        $cart = Cart::where('user_id', $this->user->id)->first();
        $cart->update(['coupon' => 'TEST10']);

        $this->checkout();

        $order = Order::where('user_id', $this->user->id)->where('status', 'pending')->first();

        // Reservation should exist
        $this->assertDatabaseHas('coupon_reservations', [
            'order_id' => $order->id,
            'coupon_id' => $coupon->id,
        ]);

        // Simulate payment success
        $orderService = app(\App\Services\General\OrderService::class);
        DB::transaction(function () use ($order, $orderService) {
            $orderReservationService = app(\App\Services\Inventory\OrderReservationService::class);
            $orderReservationService->commit($order);
            $orderService->changeOrderStatus(null, 'completed', $order->id);
        });

        // Reservation should be consumed (deleted)
        $this->assertDatabaseMissing('coupon_reservations', [
            'order_id' => $order->id,
        ]);

        // Coupon usage should be incremented
        $this->assertEquals(1, $coupon->fresh()->used);
    }

    /** @test */
    public function test_promotion_not_decremented_on_paid_order_cancellation()
    {
        $promotion = Promotion::create([
            'code' => 'PROMO10',
            'type_amount' => 'percentage',
            'discount_amount' => 10,
            'usage' => 0,
            'status' => 1,
            'is_valid' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $this->addToCart(1);

        $response = $this->postJson(self::PREFIX . '/checkout', [
            'governorate_id' => $this->governorate->id,
            'name' => 'Test',
            'user_phone' => '1234567890',
            'address' => ['street' => 'Test'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
            'selected_promotion_id' => $promotion->id,
        ]);

        $order = Order::where('user_id', $this->user->id)->first();

        // Simulate payment success
        DB::transaction(function () use ($order, $promotion) {
            $orderReservationService = app(\App\Services\Inventory\OrderReservationService::class);
            $orderReservationService->commit($order);

            $promotionService = app(\App\Services\General\PromotionService::class);
            $promotionService->incrementUsage($promotion->id);

            $order->update([
                'status' => 'completed',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
                'promotion_consumed' => true,
            ]);
        });

        $this->assertEquals(1, $promotion->fresh()->usage);

        // Cancel the paid order (Rule 17: should NOT decrement promotion)
        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->changeOrderStatus(null, 'cancelled', $order->id);

        // Promotion usage should remain 1 (NOT decremented)
        $this->assertEquals(1, $promotion->fresh()->usage, 'Paid order cancellation must NOT decrement promotion usage');
    }

    /** @test */
    public function test_paid_order_cancellation_restores_inventory()
    {
        $this->addToCart(5);
        $this->checkout();

        $order = Order::where('user_id', $this->user->id)->first();
        $initialStock = $this->product->fresh()->stock_quantity;

        // Simulate payment success
        DB::transaction(function () use ($order) {
            $orderReservationService = app(\App\Services\Inventory\OrderReservationService::class);
            $orderReservationService->commit($order);
            $order->update([
                'status' => 'completed',
                'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            ]);
        });

        $stockAfterPayment = $this->product->fresh()->stock_quantity;
        $this->assertEquals($initialStock - 5, $stockAfterPayment);

        // Cancel the paid order
        $orderService = app(\App\Services\General\OrderService::class);
        $orderService->changeOrderStatus(null, 'cancelled', $order->id);

        // Stock should be restored
        $stockAfterCancel = $this->product->fresh()->stock_quantity;
        $this->assertEquals($initialStock, $stockAfterCancel, 'Paid order cancellation must restore inventory');

        // Order should be marked as restored
        $this->assertEquals(Order::INVENTORY_STATE_RESTORED, $order->fresh()->inventory_state);
        $this->assertNotNull($order->fresh()->inventory_state_restored_at);
    }

    private function addToCart(int $quantity): void
    {
        $this->postJson(self::PREFIX . '/cart', [
            'product_id' => $this->product->id,
            'quantity' => $quantity,
        ])->assertSuccessful();
    }

    private function checkout()
    {
        return $this->postJson(self::PREFIX . '/checkout', [
            'governorate_id' => $this->governorate->id,
            'name' => 'Test User',
            'user_phone' => '1234567890',
            'user_email' => 'test@example.com',
            'address' => ['street' => '123 Test St'],
            'fulfillment_type' => 'delivery',
            'payment_method' => 'online',
        ]);
    }

    private const PREFIX = '/api/v1/general';
}
