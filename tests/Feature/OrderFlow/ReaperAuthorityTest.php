<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderStatus;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\User;
use Marvel\Enums\PromotionType;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * P3-1 (decision D3): the unpaid-order reaper (orders:cancel-unpaid) performs
 * its lifecycle mutation exclusively through the canonical writer
 * (OrderService::changeOrderStatus) — the intentional bypass is gone.
 *
 * Outcome parity pinned here:
 * - expired pending orders cancel (status/current-stage/cancelled_at,
 *   payment failed, fulfillment cancelled, inventory released),
 * - ORD-1: promotion usage is NEVER decremented for never-paid expiry
 *   cancels (skipPromotionDecrement), while ordinary canonical cancels
 *   still decrement (Rule 17) — the flag is the differentiator,
 * - the canonical first-leave-pending invoice is generated (the bypass
 *   used to suppress it),
 * - system audit history carries the expiry reason.
 */
class ReaperAuthorityTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $customer;

    private Promotion $promotion;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->customer = User::create([
            'name' => 'Reaper Customer',
            'email' => 'reaper-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);

        $this->promotion = Promotion::create([
            'name' => 'Reaper Promotion',
            'slug' => 'reaper-promotion-' . Str::random(6),
            'code' => 'REAPER-' . Str::random(6),
            'type' => PromotionType::PRICE,
            'type_amount' => 'percentage',
            'value' => 10,
            'discount' => 10,
            'minimum_order_amount' => 0,
            'apply_to' => 'all_products',
            'status' => true,
            'usage' => 5,
            'start_at' => Carbon::yesterday()->format('Y-m-d'),
            'end_at' => Carbon::tomorrow()->format('Y-m-d'),
        ]);
    }

    /**
     * An unpaid order whose reservation expired — the exact shape the
     * reaper selects. The model creation backstop assigns its flow.
     */
    private function expiredUnpaidOrder(): Order
    {
        return Order::create([
            'user_id' => $this->customer->id,
            'name' => 'Reaper Order',
            'user_phone' => '01000000000',
            'user_email' => $this->customer->email,
            'price' => 100,
            'total_price' => 100,
            'status' => 'pending',
            'payment_status' => Order::PAYMENT_STATUS_PENDING,
            'inventory_state' => Order::INVENTORY_STATE_ACTIVE,
            'reservation_expires_at' => now()->subMinute(),
            'promotion_id' => $this->promotion->id,
        ]);
    }

    private function cancelledStatusId(): int
    {
        return (int) OrderStatus::query()->where('code', 'cancelled')->value('id');
    }

    /** @test */
    public function expiry_cancel_uses_canonical_writer_and_preserves_promotion(): void
    {
        $order = $this->expiredUnpaidOrder();

        $this->artisan('orders:cancel-unpaid')->assertExitCode(0);

        $fresh = $order->fresh();

        // Lifecycle outcome parity with the old bypass.
        $this->assertSame('cancelled', $fresh->status);
        $this->assertSame($this->cancelledStatusId(), (int) $fresh->current_status_id);
        $this->assertSame(Order::PAYMENT_STATUS_FAILED, $fresh->payment_status);
        $this->assertSame(Order::FULFILLMENT_STATUS_CANCELLED, $fresh->fulfillment_status);
        $this->assertNotNull($fresh->cancelled_at);
        $this->assertSame(Order::INVENTORY_STATE_RELEASED, $fresh->inventory_state);

        // ORD-1: never-paid expiry cancels never decrement promotion usage.
        // Only the skipPromotionDecrement path produces this outcome.
        $this->assertSame(5, (int) $this->promotion->fresh()->usage);

        // Canonical side effects the bypass used to suppress/duplicate:
        // exactly one first-leave-pending invoice...
        if (Schema::hasTable('invoices')) {
            $this->assertSame(1, \App\Models\Invoice::where('order_id', $order->id)->count());
        }

        // ...and one immutable system history row carrying the expiry reason.
        if (Schema::hasTable('order_status_history')) {
            $history = \Illuminate\Support\Facades\DB::table('order_status_history')
                ->where('order_id', $order->id)
                ->latest('id')
                ->first();
            $this->assertNotNull($history);
            $this->assertSame('system', $history->changed_by_type);
            $this->assertStringContainsString('reservation expiry', (string) $history->notes);
        }
    }

    /** @test */
    public function ordinary_canonical_cancel_still_decrements_promotion(): void
    {
        $order = $this->expiredUnpaidOrder();

        // No flag: Rule 17 applies — unpaid canonical cancels decrement.
        app(OrderService::class)->changeOrderStatus(null, 'cancelled', $order->id);

        $this->assertSame('cancelled', $order->fresh()->status);
        $this->assertSame(4, (int) $this->promotion->fresh()->usage);
    }
}
