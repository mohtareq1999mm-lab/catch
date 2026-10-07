<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Refund;
use Marvel\Database\Models\User;
use Marvel\Enums\PaymentStatus;
use Marvel\Enums\RefundStatus;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * P3-3 (decision D5) + Phase 10 unification: refunds are a separate domain —
 * they never mutate the order lifecycle AND never mark payment as refunded
 * (no provider money movement exists in this phase).
 *
 * - Canonical refund approval leaves status / current_status_id /
 *   order_status untouched AND payment_status at payment-success.
 * - A non-success webhook records the payment marker only; the lifecycle
 *   never moves, and stale callbacks never clobber a resolved payment or
 *   a terminal lifecycle.
 */
class RefundWebhookFreezeTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->customer = User::create([
            'name' => 'Freeze Customer',
            'email' => 'freeze-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function completedPaidOrder(): Order
    {
        // Hook assigns the local flow; the completed start is arranged
        // directly (fixture setup, not a lifecycle transition).
        $order = Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Freeze Order',
            'user_phone' => '01000000000',
            'user_email' => $this->customer->email,
            'price' => 100,
            'total_price' => 100,
            'status' => 'completed',
            'payment_status' => Order::PAYMENT_STATUS_SUCCESS,
        ]);

        return $order->fresh();
    }

    private function statusId(string $code): int
    {
        return (int) OrderStatus::query()->where('code', $code)->value('id');
    }

    /** @test */
    public function refund_approval_is_payment_only(): void
    {
        $order = $this->completedPaidOrder();

        $refund = Refund::create([
            'order_id' => $order->id,
            'user_id' => $this->customer->id,
            'amount' => 100,
            'currency' => 'KWD',
            'title' => 'Freeze refund',
            'status' => RefundStatus::PENDING,
        ]);

        // Canonical approval (no events — pins the service writes, not the
        // fanout).
        \Illuminate\Support\Facades\Event::fake();
        app(\App\Services\Refund\RefundService::class)->approve((int) $refund->id, 1, 'freeze');

        $fresh = $order->fresh();

        // Lifecycle untouched.
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($this->statusId('completed'), (int) $fresh->current_status_id);
        $this->assertNotSame('order-refunded', $fresh->order_status);

        // Phase 10 unification: NO false payment-refunded marker — approval
        // records the business refund locally, money never moved.
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $fresh->payment_status);
        $this->assertSame('approved', $refund->fresh()->status);
    }

    /** @test */
    public function non_success_webhook_never_moves_lifecycle(): void
    {
        $order = Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Freeze Webhook Order',
            'user_phone' => '01000000000',
            'user_email' => $this->customer->email,
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
        ]);

        app(\Marvel\Database\Repositories\OrderRepository::class)
            ->webhookSuccessResponse($order->fresh(), 'order-failed', PaymentStatus::FAILED);

        $fresh = $order->fresh();

        // Lifecycle frozen.
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($this->statusId('pending'), (int) $fresh->current_status_id);
        $this->assertNotSame('order-failed', $fresh->order_status);

        // Payment marker recorded.
        $this->assertSame(PaymentStatus::FAILED, $fresh->payment_status);
    }

    /** @test */
    public function stale_non_success_webhook_cannot_clobber_resolved_order(): void
    {
        $order = $this->completedPaidOrder();

        app(\Marvel\Database\Repositories\OrderRepository::class)
            ->webhookSuccessResponse($order->fresh(), 'order-failed', PaymentStatus::FAILED);

        $fresh = $order->fresh();

        $this->assertSame('completed', $fresh->status);
        $this->assertSame(Order::PAYMENT_STATUS_SUCCESS, $fresh->payment_status);
    }
}
