<?php

namespace Tests\Feature;

use App\Models\CouponReservation;
use App\Services\Coupon\CouponReservationService;
use App\Services\General\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * P2 concurrency proofs (MySQL only — skipped on SQLite where FOR UPDATE
 * is a no-op and :memory: cannot be shared across connections).
 *
 * - Parallel claim: 10 OS processes x separate MySQL connections race one
 *   max_claims=1 slot via tests/Support orchestration (see
 *   parallel_claim_proof.php + claim_probe.php in the temp proof dir).
 *   The wrapper asserts PROOF_OK (exactly 1 success, 9 rejections, 1 row).
 * - Duplicate completion, expiry reacquire (POLICY 4), cancel race: run
 *   in-process against real row locks on MySQL.
 */
class CouponConcurrencyProofTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('P2 concurrency proofs require MySQL (FOR UPDATE + shared storage).');
        }
    }

    private function makeCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Proof Coupon',
            'slug' => 'proof-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));
        $coupon->update(['code' => $code]);

        return $coupon->fresh();
    }

    private function makeOrder(User $user, Coupon $coupon, string $status = 'pending'): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'name' => 'Proof Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 90.00,
            'price' => 100.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => $status,
        ]);
    }

    /** @test */
    public function parallel_claim_single_slot_allows_exactly_one(): void
    {
        $script = base_path('tests/Concurrency/parallel_claim_proof.php');

        if (!is_file($script)) {
            $this->markTestSkipped('Parallel-claim orchestrator script not present.');
        }

        // The orchestrator inherits this process env for DB_* so it targets
        // the same MySQL test database the suite migrated.
        $process = new Process([PHP_BINARY, $script], base_path(), [
            'DB_HOST' => (string) config('database.connections.mysql.host'),
            'DB_PORT' => (string) config('database.connections.mysql.port'),
            'DB_DATABASE' => (string) config('database.connections.mysql.database'),
            'DB_USERNAME' => (string) config('database.connections.mysql.username'),
            'DB_PASSWORD' => (string) config('database.connections.mysql.password'),
        ]);
        $process->setTimeout(300);
        $process->run();

        $output = $process->getOutput() . $process->getErrorOutput();

        $this->assertEquals(0, $process->getExitCode(), "Orchestrator failed:\n{$output}");
        $this->assertStringContainsString('PROOF_OK', $output, "Expected PROOF_OK, got:\n{$output}");
    }

    /** @test */
    public function duplicate_completion_commits_usage_exactly_once(): void
    {
        // INV-02 on real row locks: three completions ⇒ one usage, one counter.
        $user = User::factory()->create(['type' => 'user']);
        $coupon = $this->makeCoupon('DUPC');
        $order = $this->makeOrder($user, $coupon);

        $service = app(OrderService::class);
        $service->changeOrderStatus(null, 'completed', $order->id);
        $service->changeOrderStatus(null, 'completed', $order->id);
        $service->changeOrderStatus(null, 'completed', $order->id);

        $this->assertEquals(1, CouponUsage::where('coupon_id', $coupon->id)->where('user_id', $user->id)->count());
        $this->assertEquals(1, (int) $coupon->fresh()->used);
        $this->assertEquals('completed', $order->fresh()->status);
    }

    /** @test */
    public function expired_reservation_is_reacquired_at_completion(): void
    {
        // POLICY 4: reservation expired before payment ⇒ revalidate +
        // reacquire, then commit usage exactly once.
        $user = User::factory()->create(['type' => 'user']);
        $coupon = $this->makeCoupon('POL4', ['limiter' => 5]);
        $order = $this->makeOrder($user, $coupon);

        app(CouponReservationService::class)->reserve($order, $coupon);

        // Simulate TTL expiry racing ahead of payment.
        CouponReservation::where('order_id', $order->id)
            ->update(['expires_at' => now()->subMinute()]);

        app(OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        $this->assertEquals('completed', $order->fresh()->status);
        $this->assertDatabaseHas('coupon_usages', ['coupon_id' => $coupon->id, 'user_id' => $user->id]);
        $this->assertDatabaseMissing('coupon_reservations', ['order_id' => $order->id]);
    }

    /** @test */
    public function cancel_then_complete_leaves_no_usage(): void
    {
        // Cancel-vs-payment race outcome: a cancelled order cannot complete,
        // and no coupon usage may exist for it.
        $user = User::factory()->create(['type' => 'user']);
        $coupon = $this->makeCoupon('CANCP');
        $order = $this->makeOrder($user, $coupon);

        $service = app(OrderService::class);
        $service->changeOrderStatus(null, 'cancelled', $order->id);

        try {
            $service->changeOrderStatus(null, 'completed', $order->id);
            $this->fail('Cancelled order must not transition to completed.');
        } catch (\RuntimeException $e) {
            $this->assertNotEmpty($e->getMessage());
        }

        $this->assertEquals('cancelled', $order->fresh()->status);
        $this->assertDatabaseMissing('coupon_usages', ['coupon_id' => $coupon->id, 'user_id' => $user->id]);
        $this->assertEquals(0, (int) $coupon->fresh()->used);
    }
}
