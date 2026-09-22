<?php

namespace Tests\Feature;

use App\Notifications\UserCouponAssignedNotification;
use App\Services\Customer\CustomerMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CouponFinalContractTest extends TestCase
{
    use RefreshDatabase;

    private int $orderCounter = 0;

    private function createOrder(User $user, array $overrides = []): Order
    {
        $this->orderCounter++;

        return Order::create(array_merge([
            'user_id' => $user->id,
            'order_number' => 'ORD-FC-' . Str::random(8) . '-' . $this->orderCounter,
            'name' => 'Contract Customer',
            'user_phone' => '1234567890',
            'user_email' => 'contract@example.com',
            'address' => 'Contract Address',
            'status' => Order::ORDER_STATUS_COMPLETED,
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
            'price' => 100.00,
            'total_price' => 100.00,
            'converted_total_price' => 100.00,
            'currency_code' => 'USD',
            'base_currency_code' => 'USD',
            'catalog_currency_code' => 'USD',
            'currency_rate' => 1.0,
        ], $overrides));
    }

    private function createCoupon(array $overrides = []): Coupon
    {
        static $n = 0;
        $n++;

        return Coupon::create(array_merge([
            'code' => 'FCT' . $n . Str::upper(Str::random(5)),
            'name' => ['en' => 'Final Contract'],
            'discount_type' => 'percentage',
            'discount_amount' => 10,
            'status' => 'active',
            'start_date' => now()->subDay(),
            'end_date' => now()->addDays(30),
        ], $overrides));
    }

    public function test_sec2_coupons_used_counts_orders_not_distinct_codes()
    {
        // FINAL BUSINESS CONTRACT Sec 2:
        // SAVE10 + SAVE10 + SAVE20 across 3 qualifying orders = 3 (not 2).
        $user = User::factory()->create();
        $service = app(CustomerMetricsService::class);

        $this->createOrder($user, ['coupon' => 'SAVE10']);
        $this->createOrder($user, ['coupon' => 'SAVE10']);
        $this->createOrder($user, ['coupon' => 'SAVE20']);

        $metrics = $service->rebuildForUser($user);

        $this->assertEquals(3, $metrics->coupons_used);
    }

    public function test_sec2_non_qualifying_coupon_orders_excluded()
    {
        $user = User::factory()->create();
        $service = app(CustomerMetricsService::class);

        $this->createOrder($user, ['coupon' => 'SAVE10']);
        // Pending order with coupon must not count.
        $this->createOrder($user, ['coupon' => 'SAVE10', 'status' => Order::ORDER_STATUS_PENDING]);
        // Empty-string coupon snapshot must not count as usage.
        $this->createOrder($user, ['coupon' => '']);

        $metrics = $service->rebuildForUser($user);

        $this->assertEquals(1, $metrics->coupons_used);
        $this->assertEquals(2, $metrics->completed_orders);
    }

    public function test_sec3_my_coupons_returns_assignments_and_claims_for_owner()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 2,
            'used' => 1,
        ]);

        $response = $this->getJson('/api/v1/general/coupons/mine');

        $response->assertOk();
        $response->assertJsonPath('data.assignments.0.coupon_id', $coupon->id);
        $response->assertJsonPath('data.assignments.0.code', $coupon->code);
        $response->assertJsonPath('data.assignments.0.remaining', 1);
        $this->assertArrayHasKey('claims', $response->json('data'));
    }

    public function test_sec3_my_coupons_requires_auth()
    {
        $response = $this->getJson('/api/v1/general/coupons/mine');

        $response->assertUnauthorized();
    }

    public function test_sec3_my_coupons_isolates_users()
    {
        $owner = User::factory()->create();
        $other = User::factory()->create();
        Sanctum::actingAs($other);

        $coupon = $this->createCoupon();
        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $owner->id,
            'max_uses' => 1,
        ]);

        $response = $this->getJson('/api/v1/general/coupons/mine');

        $response->assertOk();
        $this->assertCount(0, $response->json('data.assignments'));
        $this->assertCount(0, $response->json('data.claims'));
    }

    public function test_sec6_assignment_notification_never_includes_mail_even_with_email()
    {
        // PART 4: Email is OUT OF SCOPE for coupon notifications. Required:
        // database + fcm + broadcast only, even when a valid email exists.
        $user = User::factory()->create(['email' => 'assigned@example.com']);
        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        $notification = new UserCouponAssignedNotification($assignment);
        $channels = $notification->via($user);

        $this->assertContains('database', $channels);
        $this->assertContains('fcm', $channels);
        $this->assertContains('broadcast', $channels);
        $this->assertNotContains('mail', $channels);
    }

    public function test_sec6_assignment_notification_skips_mail_without_email()
    {
        $user = User::factory()->create(['email' => null]);
        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        $notification = new UserCouponAssignedNotification($assignment);
        $channels = $notification->via($user);

        $this->assertContains('database', $channels);
        $this->assertNotContains('mail', $channels);
    }

    public function test_sec6_assignment_notification_skips_mail_for_invalid_email()
    {
        $user = User::factory()->create(['email' => 'not-an-email']);
        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        $notification = new UserCouponAssignedNotification($assignment);

        $this->assertNotContains('mail', $notification->via($user));
    }

    public function test_sec6_assignment_tomail_renders_without_exception()
    {
        $user = User::factory()->create(['email' => 'assigned@example.com']);
        $coupon = $this->createCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        $mail = (new UserCouponAssignedNotification($assignment))->toMail($user);

        $this->assertInstanceOf(\Illuminate\Notifications\Messages\MailMessage::class, $mail);
        $this->assertNotEmpty($mail->subject);
    }
}
