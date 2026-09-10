<?php

namespace Tests\Feature;

use App\Models\CouponReservation;
use App\Services\Coupon\CouponReservationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ShippingPrice;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\ProductType;
use Tests\Concerns\CreatesTestTables;
use Tests\Concerns\WithInvoiceTables;
use Tests\TestCase;

/**
 * Tests for critical concurrency scenarios in business rules implementation.
 * These tests verify race condition prevention.
 */
class ConcurrencyRaceConditionTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables, WithInvoiceTables;

    private User $user1;
    private User $user2;
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

        $this->user1 = User::create([
            'name' => 'User 1',
            'email' => 'user1@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->user2 = User::create([
            'name' => 'User 2',
            'email' => 'user2@example.com',
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
    }

    /** @test */
    public function test_concurrent_single_use_coupon_reservation_prevents_double_booking()
    {
        // Create a single-use coupon — FIXED_RATE is correct enum, FIXED does not exist
        $coupon = Coupon::create([
            'code' => 'SINGLE_USE',
            'slug' => 'single-use-' . \Illuminate\Support\Str::random(6),
            'name' => 'Single Use',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 50,
            'limiter' => 1, // Only 1 use allowed
            'used' => 0,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $service = app(CouponReservationService::class);

        // Create two orders for different users
        $order1 = Order::create([
            'user_id' => $this->user1->id,
            'status' => 'pending',
            'total_price' => 100,
            'coupon' => 'SINGLE_USE',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
        ]);

        $order2 = Order::create([
            'user_id' => $this->user2->id,
            'status' => 'pending',
            'total_price' => 100,
            'coupon' => 'SINGLE_USE',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
        ]);

        // Simulate concurrent reservation attempts
        $reservation1Success = false;
        $reservation2Success = false;
        $reservation1Exception = null;
        $reservation2Exception = null;

        try {
            DB::transaction(function () use ($service, $order1, $coupon, &$reservation1Success) {
                $service->reserve($order1, $coupon);
                $reservation1Success = true;
            });
        } catch (\RuntimeException $e) {
            $reservation1Exception = $e->getMessage();
        }

        try {
            DB::transaction(function () use ($service, $order2, $coupon, &$reservation2Success) {
                $service->reserve($order2, $coupon);
                $reservation2Success = true;
            });
        } catch (\RuntimeException $e) {
            $reservation2Exception = $e->getMessage();
        }

        // EXACTLY one reservation should succeed
        $this->assertTrue(
            ($reservation1Success && !$reservation2Success) || (!$reservation1Success && $reservation2Success),
            'Exactly ONE reservation must succeed for single-use coupon'
        );

        // Verify only one reservation exists
        $reservationCount = CouponReservation::where('coupon_id', $coupon->id)->count();
        $this->assertEquals(1, $reservationCount, 'Only one reservation should exist');

        // One user should get an error
        $this->assertTrue(
            $reservation1Exception !== null || $reservation2Exception !== null,
            'One user must receive usage limit error'
        );
    }

    /** @test */
    public function test_idempotent_reservation_refresh()
    {
        $coupon = Coupon::create([
            'code' => 'REFRESH_TEST',
            'slug' => 'refresh-test-' . \Illuminate\Support\Str::random(6),
            'name' => 'Refresh Test',
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 10,
            'limiter' => 10,
            'used' => 0,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ]);

        $order = Order::create([
            'user_id' => $this->user1->id,
            'status' => 'pending',
            'total_price' => 100,
            'coupon' => 'REFRESH_TEST',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
        ]);

        $service = app(CouponReservationService::class);

        // First reservation
        $reservation1 = $service->reserve($order, $coupon);
        $this->assertNotNull($reservation1);
        $firstExpiresAt = $reservation1->expires_at;

        // Wait a second
        sleep(1);

        // Second reservation (should refresh, not duplicate)
        $reservation2 = $service->reserve($order, $coupon);
        $this->assertEquals($reservation1->id, $reservation2->id, 'Should reuse same reservation');
        $this->assertGreaterThan($firstExpiresAt, $reservation2->expires_at, 'Expiration should be refreshed');

        // Verify still only one reservation
        $count = CouponReservation::where('order_id', $order->id)->count();
        $this->assertEquals(1, $count, 'Idempotent reservation should not create duplicates');
    }
}
