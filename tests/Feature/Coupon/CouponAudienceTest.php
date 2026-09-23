<?php

namespace Tests\Feature\Coupon;

use App\Enums\CouponDistributionTriggerType;
use App\Events\Coupons\CouponActivated;
use App\Listeners\Coupons\StartCouponDistribution;
use App\Models\CouponOutbox;
use App\Services\Coupon\Audience\CouponAudienceResolver;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\Coupon\Distribution\Consumers\DistributionStartHandler;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\NonDistributableCouponException;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Audience + assignment-override + targeting-union + delayed distribution.
 *
 * Audience (who receives) stays separate from eligibility (who may use):
 * assignment quota is an OVERRIDE (never additive), OR-mode is the union
 * path, and trigger-driven fan-out waits distribution_delay_seconds while
 * the coupon itself is valid immediately.
 */
class CouponAudienceTest extends TestCase
{
    use RefreshDatabase;

    private FakeCouponEventTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fake = new FakeCouponEventTransport();
        $this->app->singleton(FakeCouponEventTransport::class, fn () => $this->fake);
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        $code = 'AUD-' . strtoupper(Str::random(6));

        return Coupon::create(array_merge([
            'code' => $code,
            'slug' => 'aud-' . strtolower(Str::random(6)),
            'name' => 'Audience coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));
    }

    private function makeOrder(User $user, Coupon $coupon): Order
    {
        return Order::create([
            'user_id' => $user->id,
            'name' => 'Audience order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 90.00,
            'price' => 100.00,
            'coupon' => $coupon->fresh()->code,
            'coupon_discount' => 10,
            'status' => 'pending',
        ]);
    }

    private function completeOrder(User $user, Coupon $coupon): Order
    {
        $order = $this->makeOrder($user, $coupon);
        app(\App\Services\Coupon\CouponReservationService::class)->reserve($order, $coupon->fresh());
        app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        return $order->fresh();
    }

    // ---- Audience resolver classification ----

    public function test_resolver_marks_pure_coupon_public()
    {
        $coupon = $this->makeCoupon();

        $this->assertSame(['public' => true, 'assigned' => false, 'targeted' => false], app(CouponAudienceResolver::class)->sources($coupon));
        $this->assertSame('public', app(CouponAudienceResolver::class)->describe($coupon));
        $this->assertTrue($coupon->isPublic());
    }

    public function test_resolver_marks_assignment_only_coupon()
    {
        $coupon = $this->makeCoupon();
        $user = User::factory()->create();
        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => $user->id, 'max_uses' => 1]);

        $sources = app(CouponAudienceResolver::class)->sources($coupon);
        $this->assertSame(['public' => false, 'assigned' => true, 'targeted' => false], $sources);
        $this->assertSame('assigned', app(CouponAudienceResolver::class)->describe($coupon));
        // Frozen isPublic() semantics untouched.
        $this->assertFalse($coupon->fresh()->isPublic());
    }

    public function test_resolver_marks_targeted_and_combined_coupons()
    {
        $targeted = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $targeted->id, 'mode' => 'dynamic', 'rule_tree' => null]);
        $this->assertSame('targeted', app(CouponAudienceResolver::class)->describe($targeted));

        $combo = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $combo->id, 'mode' => 'assignment_or_dynamic', 'rule_tree' => null]);
        CouponAssignment::create(['coupon_id' => $combo->id, 'user_id' => User::factory()->create()->id, 'max_uses' => 1]);
        $this->assertSame('assigned+targeted', app(CouponAudienceResolver::class)->describe($combo));
    }

    // ---- Assignment override (§4): max_uses replaces base, never adds ----

    public function test_assignment_max_uses_overrides_base_limit_for_that_user()
    {
        $ahmed = User::factory()->create();
        // Base coupon: unlimited global capacity (limiter null) so the
        // personal quota is the only gate under test.
        $coupon = $this->makeCoupon(['limiter' => null]);
        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => $ahmed->id, 'max_uses' => 2]);

        $this->completeOrder($ahmed, $coupon);
        $this->completeOrder($ahmed, $coupon);

        $this->assertSame(2, $coupon->fresh()->used);
        $this->assertSame(2, CouponAssignment::where('coupon_id', $coupon->id)->where('user_id', $ahmed->id)->value('used'));

        // Third use exceeds the personal quota (2), not base+assignment (3).
        $order = $this->makeOrder($ahmed, $coupon);
        try {
            app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'completed', $order->id);
            $this->fail('Third completion must be rejected by assignment quota.');
        } catch (\App\Exceptions\CouponConsumptionException $e) {
            $this->assertSame(2, $coupon->fresh()->used);
        }
    }

    public function test_user_without_assignment_keeps_base_behavior()
    {
        $coupon = $this->makeCoupon(['limiter' => null]);
        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => User::factory()->create()->id, 'max_uses' => 5]);

        $mohamed = User::factory()->create();
        $result = CouponOrchestrator::validate($coupon, $mohamed);

        // Assignment-family gate unchanged: unassigned users are rejected
        // (frozen POLICY 1), never silently granted base usage.
        $this->assertFalse($result['valid']);
        $this->assertSame('not_assigned', $result['reason']);
    }

    // ---- Assigned + targeted union (§6/§34) via OR mode ----

    public function test_or_mode_unites_assigned_and_target_paths_with_single_outcome()
    {
        $coupon = $this->makeCoupon(['limiter' => null]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment_or_dynamic',
            'rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 1000]]],
        ]);

        // A: assigned only (rule fails) → valid via assignment path.
        $userA = User::factory()->create();
        CustomerMetrics::create(['user_id' => $userA->id, 'completed_orders' => 0, 'total_qualifying_order_value' => 0]);
        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => $userA->id, 'max_uses' => 1]);
        $this->assertTrue(CouponOrchestrator::validate($coupon, $userA)['valid']);

        // B: target only (rule passes, no assignment) → valid via tree path.
        $userB = User::factory()->create();
        CustomerMetrics::create(['user_id' => $userB->id, 'completed_orders' => 1000, 'total_qualifying_order_value' => 0]);
        $this->assertTrue(CouponOrchestrator::validate($coupon, $userB)['valid']);

        // D: neither → rejected.
        $userD = User::factory()->create();
        CustomerMetrics::create(['user_id' => $userD->id, 'completed_orders' => 0, 'total_qualifying_order_value' => 0]);
        $this->assertFalse(CouponOrchestrator::validate($coupon, $userD)['valid']);
    }

    // ---- Delayed distribution (§10–11) ----

    public function test_delayed_outbox_row_waits_for_its_window_in_the_sweep()
    {
        $service = app(CouponOutboxService::class);
        $row = $service->recordDelayed(
            CouponEventEnvelope::create(CouponDistributionEvents::DISTRIBUTION_START, aggregateId: 1, payload: ['run_id' => 1]),
            600
        );

        $this->assertTrue($row->available_at->isFuture());
        // The automatic path (minutely sweep) skips the delayed row…
        $this->assertSame(0, $service->publishDue(10));
        $this->assertCount(0, $this->fake->published);

        // …until the window lapses, then delivers exactly once.
        $row->update(['available_at' => now()->subMinute()]);
        $this->assertSame(1, $service->publishDue(10));
        $this->assertCount(1, $this->fake->published);
    }

    public function test_triggered_distribution_run_is_delayed_but_coupon_stays_valid()
    {
        $coupon = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => 'dynamic', 'rule_tree' => null]);

        (new StartCouponDistribution())->handle(new CouponActivated($coupon));

        $row = CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::DISTRIBUTION_START)
            ->latest('id')->firstOrFail();

        $this->assertSame('pending', $row->status->value);
        $this->assertTrue($row->available_at->isFuture());
        // Coupon itself valid immediately (validity ≠ notification).
        $this->assertTrue($coupon->fresh()->status);
        $this->assertCount(0, $this->fake->published);
    }

    public function test_stale_run_aborts_when_targeting_changed_inside_delay_window()
    {
        $coupon = $this->makeCoupon();
        $targeting = CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => 'dynamic', 'rule_tree' => null]);

        /** @var DistributionService $service */
        $service = app(DistributionService::class);
        ['run' => $run] = $service->startDistribution($coupon, CouponDistributionTriggerType::COUPON_ACTIVATED, 'activation', null, null, null, 0);

        // Admin edits targeting inside the (here zero, but logic-identical) window.
        $targeting->update(['rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 5]]]]);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: $coupon->getKey(),
            payload: ['run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey()]
        );

        $this->expectException(NonDistributableCouponException::class);
        app(DistributionStartHandler::class)->handle($envelope);
    }

    // ---- Assignment-aware fan-out union (§6) ----

    public function test_distribution_candidates_include_assigned_users()
    {
        $coupon = $this->makeCoupon();
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment_or_dynamic',
            'rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 1000000]]],
        ]);
        $assignee = User::factory()->create();
        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => $assignee->id, 'max_uses' => 1]);

        /** @var DistributionService $service */
        $service = app(DistributionService::class);
        ['run' => $run] = $service->startDistribution($coupon, CouponDistributionTriggerType::COUPON_ACTIVATED, 'activation', null, null, null, 0);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: $coupon->getKey(),
            payload: ['run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey(), 'audience_cap' => 10000]
        );

        app(DistributionStartHandler::class)->handle($envelope);

        // Assigned user present exactly once despite rule-ineligibility.
        $this->assertSame(1, \App\Models\CouponDistributionRecipient::query()
            ->where('run_id', $run->getKey())->where('user_id', $assignee->id)->count());
    }

    // ---- Failure paths (§38): fail safe, never fan out stale state ----

    public function test_disabled_coupon_run_cancels_without_candidates()
    {
        $coupon = $this->makeCoupon(['status' => false]);
        CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => 'dynamic', 'rule_tree' => null]);

        /** @var DistributionService $service */
        $service = app(DistributionService::class);
        ['run' => $run] = $service->startDistribution($coupon, CouponDistributionTriggerType::COUPON_ACTIVATED, 'activation', null, null, null, 0);

        $result = app(DistributionStartHandler::class)->handle(CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: $coupon->getKey(),
            payload: ['run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey(), 'audience_cap' => 100]
        ));

        $this->assertSame(0, $result['chunks']);
        $this->assertSame('cancelled', $run->fresh()->status->value);
    }

    public function test_duplicate_start_event_never_double_counts_recipients()
    {
        $coupon = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => 'dynamic', 'rule_tree' => null]);
        $assignee = User::factory()->create();
        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => $assignee->id, 'max_uses' => 1]);

        /** @var DistributionService $service */
        $service = app(DistributionService::class);
        ['run' => $run] = $service->startDistribution($coupon, CouponDistributionTriggerType::COUPON_ACTIVATED, 'activation', null, null, null, 0);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: $coupon->getKey(),
            payload: ['run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey(), 'audience_cap' => 10000]
        );

        // Same redelivered start event twice: recipients converge to one row.
        app(DistributionStartHandler::class)->handle($envelope);
        app(DistributionStartHandler::class)->handle($envelope);

        $this->assertSame(1, \App\Models\CouponDistributionRecipient::query()
            ->where('run_id', $run->getKey())->where('user_id', $assignee->id)->count());
    }

    public function test_deleted_coupon_trigger_drops_job_without_retry()
    {
        $coupon = $this->makeCoupon();
        $event = new \App\Events\Coupons\CouponActivated($coupon);
        $coupon->delete();

        // Must not throw (a throw would retry then land in failed_jobs).
        (new \App\Listeners\Coupons\StartCouponDistribution())->handle($event);

        $this->assertSame(0, \App\Models\CouponDistributionRun::query()->count());
    }
}
