<?php

namespace Tests\Feature;

use App\Events\PaymentSucceeded;
use App\ValueObjects\TransitionActor;
use App\Services\Order\OrderTransitionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Marvel\Enums\DiscountType;
use Marvel\Enums\PaymentStatus;
use Marvel\Traits\PaymentTrait;
use Tests\TestCase;

class WebhookPaymentCompletionTest extends TestCase
{
    use RefreshDatabase, PaymentTrait;

    /**
     * Marvel commerce models (Order/Product/Coupon/Transaction) ship without
     * HasFactory, so every fixture is written explicitly. RefreshDatabase
     * already migrates the schema once per class; re-running migrate:fresh
     * per test only paid the full 185-migration cost five times.
     */
    private function makeCoupon(string $code, array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => $code,
            'name' => 'Webhook ' . $code,
            'slug' => 'webhook-' . strtolower($code),
            'discount_type' => DiscountType::FIXED_RATE,
            'discount' => 10,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'limiter' => 100,
            'used' => 0,
            'status' => true,
        ], $overrides));
    }

    private function makeOrder(User $user, array $overrides = []): Order
    {
        return Order::create(array_merge([
            'user_id' => $user->id,
            'name' => 'Webhook Order',
            'user_phone' => '01000000000',
            'user_email' => $user->email,
            'address' => ['street' => 'Test'],
            'price' => 90,
            'total_price' => 90,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'payment_method' => 'online',
            'payment_gateway' => 'myfatoorah',
            'fulfillment_type' => 'delivery',
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'coupon_consumed' => false,
        ], $overrides));
    }

    /**
     * Completion is gated by the order's assigned flow. The Order model's
     * creating hook assigns the active local flow and mirrors `status` into
     * `current_status_id`, so raw creates are production-shaped as long as
     * model events are not faked wholesale.
     */
    private function makeTransaction(Order $order, User $user): Transaction
    {
        return Transaction::create([
            'order_id' => $order->id,
            'invoice_id' => $order->id,
            'user_id' => $user->id,
            'uuid' => (string) Str::uuid(),
            'payment_method' => 'online',
            'status' => 'pending',
            'amount' => 90,
        ]);
    }

    /** @test */
    public function webhook_success_completes_order_and_redeems_coupon()
    {
        Event::fake([PaymentSucceeded::class]);

        $user = User::factory()->create(['type' => 'user']);
        $coupon = $this->makeCoupon('TEST10');
        $order = $this->makeOrder($user, [
            'price' => 100,
            'coupon' => 'TEST10',
            'coupon_discount' => 10,
        ]);
        $this->makeTransaction($order, $user);

        $this->webhookSuccessResponse($order, 'order-processing', PaymentStatus::SUCCESS);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'completed',
            'payment_status' => 'payment-success',
            'coupon_consumed' => true,
        ]);

        $this->assertDatabaseHas('coupons', [
            'id' => $coupon->id,
            'used' => 1,
        ]);

        Event::assertDispatched(PaymentSucceeded::class);
    }

    /** @test */
    public function webhook_idempotent_duplicate_callback_ignored()
    {
        // Fake only the payment event: a blanket Event::fake() also disables
        // the Order creating hook that assigns the NOT NULL flow columns.
        Event::fake([PaymentSucceeded::class]);

        $user = User::factory()->create(['type' => 'user']);
        $order = $this->makeOrder($user, [
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ]);

        $initialUpdatedAt = $order->updated_at;

        // Call webhook again
        $this->webhookSuccessResponse($order, 'order-processing', PaymentStatus::SUCCESS);

        // Order unchanged
        $order->refresh();
        $this->assertEquals('completed', $order->status);
        $this->assertEquals($initialUpdatedAt, $order->updated_at);
    }

    /** @test */
    public function webhook_on_cancelled_order_does_nothing()
    {
        // The legacy success path fires PaymentSucceeded even when the
        // lifecycle branch early-returns; its invoice listener is unrelated
        // to the assertion (the order must stay cancelled).
        Event::fake([PaymentSucceeded::class]);

        $user = User::factory()->create(['type' => 'user']);
        $order = $this->makeOrder($user, [
            'status' => 'cancelled',
            'payment_status' => 'payment-failed',
        ]);

        $this->webhookSuccessResponse($order, 'order-processing', PaymentStatus::SUCCESS);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'status' => 'cancelled',
        ]);
    }

    /** @test */
    public function coupon_locked_before_increment()
    {
        $coupon = $this->makeCoupon('CONCURRENT', ['used' => 98, 'limiter' => 100]);

        $orders = [];
        foreach (range(1, 3) as $i) {
            // One pending order per user: the partial unique index forbids
            // stacking three pending orders on a single buyer.
            $user = User::factory()->create(['type' => 'user']);
            $orders[] = $this->makeOrder($user, [
                'coupon' => 'CONCURRENT',
            ]);
        }

        // Complete all orders
        foreach ($orders as $order) {
            try {
                DB::transaction(function () use ($order) {
                    app(OrderTransitionService::class)->completeFromGateway($order, TransitionActor::gateway());
                });
            } catch (\Throwable $e) {
                // Expected: the overflow order loses the capacity race.
            }
        }

        // Exactly two redemptions fit under the limiter; the third fails closed.
        $coupon->refresh();
        $this->assertEquals(100, $coupon->used);
    }

    /** @test */
    public function reservation_preserved_when_validation_fails()
    {
        $user = User::factory()->create(['type' => 'user']);
        $otherUser = User::factory()->create(['type' => 'user']);

        $coupon = $this->makeCoupon('ASSIGNED', ['limiter' => 1]);

        // Assignment-family coupon: a whitelist exists, but not for this buyer.
        // Without the targeting row the coupon would fall through to the
        // public single-use path and complete.
        DB::table('coupon_targetings')->insert([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment',
            'require_claim' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('coupon_assignments')->insert([
            'coupon_id' => $coupon->id,
            'user_id' => $otherUser->id,
            'max_uses' => 1,
            'used' => 0,
            'assigned_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $order = $this->makeOrder($user, [
            'coupon' => 'ASSIGNED',
        ]);

        // Live reservation made at checkout: completion proceeds on that hold,
        // so the redemption gate itself is what must fail.
        DB::table('coupon_reservations')->insert([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'order_id' => $order->id,
            'reserved_at' => now(),
            'expires_at' => now()->addMinutes(30),
        ]);

        $thrown = null;
        try {
            DB::transaction(function () use ($order) {
                app(OrderTransitionService::class)->completeFromGateway($order, TransitionActor::gateway());
            });
        } catch (\Throwable $e) {
            $thrown = $e;
        }

        $this->assertInstanceOf(
            \App\Exceptions\CouponConsumptionException::class,
            $thrown,
            'An unassigned buyer must fail closed, never complete on a free discount'
        );

        // Reservation consumption happens only AFTER successful redemption.
        $this->assertTrue(
            DB::table('coupon_reservations')->where('order_id', $order->id)->exists(),
            'A failed validation must leave the reservation intact'
        );

        $this->assertDatabaseHas('orders', ['id' => $order->id, 'status' => 'pending']);
        $this->assertDatabaseHas('coupons', ['id' => $coupon->id, 'used' => 0]);
    }
}
