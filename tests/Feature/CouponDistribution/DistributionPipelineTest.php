<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionTriggerType;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Messaging\CouponEventTransport;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

class DistributionPipelineTest extends TestCase
{
    use RefreshDatabase;

    private FakeCouponEventTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();

        NullFcmChannel::reset();
        \Illuminate\Support\Facades\Notification::extend('fcm', fn () => new NullFcmChannel());

        $this->fake = new FakeCouponEventTransport();
        $this->fake->declareTopology();
        $this->app->singleton(FakeCouponEventTransport::class, fn () => $this->fake);
    }

    private function createDynamicCoupon(array $ruleTree): Coupon
    {
        $code = 'PIPE-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Pipeline Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => $ruleTree,
        ]);

        return $coupon;
    }

    private function pump(int $maxMessages = 50): void
    {
        $outbox = app(CouponOutboxService::class);

        // Drain: publish due outbox rows, then consume each work queue,
        // repeating while outbox rows stay pending or fake queues hold
        // messages (bounded). Artisan::call returns exit codes, so
        // progress is measured from queue depths, not return values.
        for ($i = 0; $i < 12; $i++) {
            $outbox->publishDue(200);

            foreach (['distribution', 'evaluation', 'notifications'] as $queue) {
                Artisan::call('coupon:consume', [
                    '--queue' => $queue,
                    '--max-messages' => $maxMessages,
                    '--max-seconds' => 20,
                ]);
            }

            $pendingOutbox = \App\Models\CouponOutbox::query()
                ->where('status', 'pending')
                ->where('available_at', '<=', now())
                ->exists();

            $queuedMessages = array_sum(array_map('count', $this->fake->queues));

            if (! $pendingOutbox && $queuedMessages === 0) {
                break;
            }
        }
    }

    public function test_full_lifecycle_eligible_users_get_notified_once()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);

        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);
        }

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'),
            CouponDistributionTriggerType::MANUAL,
            'manual',
        );

        $this->assertTrue($result['created']);

        $this->pump();

        $run = $result['run']->fresh();

        $this->assertSame('completed', $run->status->value);
        $this->assertSame(3, $run->candidate_count);
        $this->assertSame(3, $run->notified_count);
        $this->assertSame(0, $run->failed_count);

        // Durable per-user notified state, exactly once each.
        $this->assertSame(3, \App\Models\CouponDistributionUserState::query()
            ->where('coupon_id', $coupon->id)
            ->where('state', 'notified')
            ->count());

        // One owner-scoped database notification each, type coupon.eligible.
        foreach ($users as $user) {
            $rows = $user->fresh()->notifications()->where('type', 'coupon.eligible')->get();
            $this->assertCount(1, $rows);

            $data = $rows->first()->data;
            $data = is_string($data) ? json_decode($data, true) : $data;

            $this->assertSame($coupon->id, $data['coupon_id']);
            $this->assertArrayNotHasKey('coupon_code', $data);
            $this->assertArrayNotHasKey('code', $data);
            $this->assertArrayNotHasKey('rule_tree', $data);
            $this->assertArrayNotHasKey('rules', $data);
        }

        // Distribution creates neither assignments nor claims.
        $this->assertSame(0, \Marvel\Database\Models\CouponAssignment::query()->count());
        $this->assertSame(0, \Marvel\Database\Models\CouponClaim::query()->count());

        // Correlation chain is traceable end to end.
        $trace = app(CouponEventLogService::class)
            ->traceByCorrelation($this->fake->published[0]->correlationId);
        $types = collect($trace)->map->event_type->all();

        $this->assertContains('coupon.distribution.start', $types);
        $this->assertContains('coupon.user.became_eligible', $types);
        $this->assertContains('coupon.notification.sent', $types);
    }

    public function test_ineligible_users_are_skipped_without_notification()
    {
        // Unknown rule: the selector stays broad (never excludes on
        // ignorance) while the Engine fail-closes → not_eligible recipient.
        $coupon = $this->createDynamicCoupon(['type' => 'future_rule_xyz', 'value' => 1]);

        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'),
            CouponDistributionTriggerType::MANUAL,
            'manual',
        );

        $this->pump();

        $this->assertSame(0, $user->fresh()->notifications()->where('type', 'coupon.eligible')->count());
        $this->assertDatabaseHas('coupon_distribution_recipients', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'status' => 'not_eligible',
        ]);
    }

    public function test_completed_manual_run_reopens_as_new_run_without_duplicate_notify()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        $service = app(DistributionService::class);

        $first = $service->startDistribution($coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual');
        $this->pump();
        $this->assertSame('completed', $first['run']->fresh()->status->value);

        // Explicit re-run after terminal state opens a NEW run (B2), while
        // cross-run notified state keeps exactly-once notification.
        $second = $service->startDistribution($coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual');
        $this->assertTrue($second['created']);
        $this->assertNotSame($first['run']->getKey(), $second['run']->getKey());
        $this->pump();

        $this->assertCount(1, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());
    }

    public function test_new_user_event_after_terminal_run_reevaluates()
    {
        // Order N finds the user ineligible; order N+k (new event) must
        // re-evaluate instead of joining the terminal run (B1).
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 5]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $service = app(DistributionService::class);
        $scope = 'user:'.$user->id;

        $first = $service->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::ORDER_COMPLETED, $scope, 'order:101'
        );
        $this->pump();
        $this->assertSame('completed', $first['run']->fresh()->status->value);
        $this->assertCount(0, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());

        // User becomes eligible; new order event re-opens evaluation.
        \Marvel\Database\Models\CustomerMetrics::query()->where('user_id', $user->id)
            ->update(['completed_orders' => 9]);

        $second = $service->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::ORDER_COMPLETED, $scope, 'order:102'
        );
        $this->assertTrue($second['created']);
        $this->pump();

        $this->assertCount(1, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());
    }

    public function test_new_tree_version_reopens_notification()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        $service = app(DistributionService::class);
        $service->startDistribution($coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual');
        $this->pump();

        // Targeting change → new tree hash → new run → second notification.
        $coupon->targeting->update(['rule_tree' => ['type' => 'min_completed_orders', 'value' => 1]]);
        $service->startDistribution($coupon->fresh('targeting'), CouponDistributionTriggerType::TARGETING_CHANGED, 'activation');
        $this->pump();

        $this->assertCount(2, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());
    }
}
