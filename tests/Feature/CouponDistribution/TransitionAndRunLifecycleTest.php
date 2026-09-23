<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionTriggerType;
use App\Models\CouponDistributionRun;
use App\Services\Coupon\Distribution\Consumers\DistributionChunkHandler;
use App\Services\Coupon\Distribution\DistributionRunService;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\EligibilityTransitionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

class TransitionAndRunLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private FakeCouponEventTransport $fake;

    protected function setUp(): void
    {
        parent::setUp();

        NullFcmChannel::reset();
        Notification::extend('fcm', fn () => new NullFcmChannel());

        $this->fake = new FakeCouponEventTransport();
        $this->fake->declareTopology();
        $this->app->singleton(FakeCouponEventTransport::class, fn () => $this->fake);
    }

    private function createDynamicCoupon(array $ruleTree): Coupon
    {
        $code = 'TRN-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Transition Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => $ruleTree,
        ]);

        return $coupon;
    }

    private function pump(): void
    {
        $outbox = app(CouponOutboxService::class);

        for ($i = 0; $i < 12; $i++) {
            $outbox->publishDue(200);

            foreach (['distribution', 'evaluation', 'notifications'] as $queue) {
                Artisan::call('coupon:consume', [
                    '--queue' => $queue, '--max-messages' => 50, '--max-seconds' => 20,
                ]);
            }

            $pending = \App\Models\CouponOutbox::query()
                ->where('status', 'pending')->where('available_at', '<=', now())->exists();
            $queued = array_sum(array_map('count', $this->fake->queues));

            if (! $pending && $queued === 0) {
                break;
            }
        }
    }

    public function test_duplicate_evaluation_before_notify_emits_single_request()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $run = $result['run'];

        $recipient = \App\Models\CouponDistributionRecipient::query()->create([
            'run_id' => $run->id, 'coupon_id' => $coupon->id, 'user_id' => $user->id,
            'tree_hash' => $run->tree_hash, 'status' => 'discovered',
        ]);

        $cause = CouponEventEnvelope::create(CouponDistributionEvents::USER_EVALUATE, $coupon->id);
        $transitions = app(EligibilityTransitionService::class);

        $first = $transitions->evaluate($coupon, $user, $run, $recipient->fresh(), $cause, $run->tree_hash);
        // Simulate a duplicate evaluation before the notifier commits.
        $second = $transitions->evaluate($coupon, $user, $run, $recipient->fresh(), $cause, $run->tree_hash);

        $this->assertSame(EligibilityTransitionService::OUTCOME_NOTIFIED_PATH, $first['outcome']);
        $this->assertSame(EligibilityTransitionService::OUTCOME_DUPLICATE, $second['outcome']);

        // Exactly one notification.requested outbox row (B3).
        $this->assertSame(1, \App\Models\CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::NOTIFICATION_REQUESTED)
            ->count());
    }

    public function test_new_tree_hash_reopens_notified_user_for_reevaluation()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $run = $result['run'];

        $recipient = \App\Models\CouponDistributionRecipient::query()->create([
            'run_id' => $run->id, 'coupon_id' => $coupon->id, 'user_id' => $user->id,
            'tree_hash' => $run->tree_hash, 'status' => 'discovered',
        ]);

        $cause = CouponEventEnvelope::create(CouponDistributionEvents::USER_EVALUATE, $coupon->id);
        $transitions = app(EligibilityTransitionService::class);

        $first = $transitions->evaluate($coupon, $user, $run, $recipient->fresh(), $cause, $run->tree_hash);
        $this->assertSame(EligibilityTransitionService::OUTCOME_NOTIFIED_PATH, $first['outcome']);

        // Same version again: duplicate, never a second request.
        $repeat = $transitions->evaluate($coupon, $user, $run, $recipient->fresh(), $cause, $run->tree_hash);
        $this->assertSame(EligibilityTransitionService::OUTCOME_DUPLICATE, $repeat['outcome']);

        // New targeting version: legitimate reevaluation, exactly one more request.
        $v2 = str_repeat('b', 64);
        $third = $transitions->evaluate($coupon, $user, $run, $recipient->fresh(), $cause, $v2);
        $this->assertSame(EligibilityTransitionService::OUTCOME_NOTIFIED_PATH, $third['outcome']);

        $this->assertSame(2, \App\Models\CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::NOTIFICATION_REQUESTED)
            ->count());
    }

    public function test_maybe_finish_matrix()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $service = app(DistributionRunService::class);
        $cause = CouponEventEnvelope::create(CouponDistributionEvents::USER_EVALUATE, $coupon->id);

        $makeRun = function () use ($coupon) {
            return CouponDistributionRun::query()->create([
                'coupon_id' => $coupon->id, 'trigger_type' => 'manual',
                'tree_hash' => str_repeat('a', 64), 'dedupe_key' => 'k-'.Str::random(12),
                'status' => 'running',
            ]);
        };
        $addRecipient = function ($run, $status) use ($coupon) {
            $user = User::factory()->create();

            return \App\Models\CouponDistributionRecipient::query()->create([
                'run_id' => $run->id, 'coupon_id' => $coupon->id, 'user_id' => $user->id,
                'tree_hash' => str_repeat('a', 64), 'status' => $status,
            ]);
        };

        // Mixed notified + not_eligible → COMPLETED.
        $run = $makeRun();
        $addRecipient($run, 'notified');
        $addRecipient($run, 'not_eligible');
        $service->maybeFinishRun($run, $cause);
        $this->assertSame('completed', $run->fresh()->status->value);

        // Any failed_permanent → FAILED.
        $run = $makeRun();
        $addRecipient($run, 'notified');
        $addRecipient($run, 'failed_permanent');
        $service->maybeFinishRun($run, $cause);
        $this->assertSame('failed', $run->fresh()->status->value);

        // Open eligible work → stays running.
        $run = $makeRun();
        $addRecipient($run, 'notified');
        $addRecipient($run, 'eligible');
        $service->maybeFinishRun($run, $cause);
        $this->assertSame('running', $run->fresh()->status->value);

        // Retryable failure still in flight → stays running.
        $run = $makeRun();
        $addRecipient($run, 'notified');
        $addRecipient($run, 'failed_retryable');
        $service->maybeFinishRun($run, $cause);
        $this->assertSame('running', $run->fresh()->status->value);
    }

    public function test_terminal_run_drops_late_work_without_side_effects()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $this->pump();
        $run = $result['run']->fresh();
        $this->assertSame('completed', $run->status->value);

        $recipient = \App\Models\CouponDistributionRecipient::query()
            ->where('run_id', $run->id)->firstOrFail();

        // Late redelivery to a completed run: dropped, no duplicate notify.
        $late = CouponEventEnvelope::create(
            CouponDistributionEvents::NOTIFICATION_REQUESTED, $coupon->id,
            ['run_id' => $run->id, 'recipient_id' => $recipient->id,
                'coupon_id' => $coupon->id, 'user_id' => $user->id, 'tree_hash' => $run->tree_hash],
            userId: $user->id,
        );
        app(\App\Services\Coupon\Distribution\Consumers\NotificationRequestHandler::class)
            ->handle($late);

        $this->assertCount(1, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());
        $this->assertSame('completed', $run->fresh()->status->value);
    }

    public function test_poison_chunk_dead_letters_immediately()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        [$run] = app(DistributionRunService::class)->startOrJoin(
            $coupon->id, str_repeat('a', 64),
            \App\Enums\CouponDistributionTriggerType::MANUAL, 'manual'
        );

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_CHUNK, $coupon->id,
            ['run_id' => $run->id, 'coupon_id' => $coupon->id, 'user_ids' => []]
        );
        app(CouponEventLogService::class)->recordPublished($envelope);

        $command = app(\App\Console\Commands\Coupons\ConsumeCouponQueueCommand::class);
        $outcome = $command->processOne(
            ['body' => $envelope->toJson(), 'headers' => ['x-attempt' => 1], 'redelivered' => false],
            'distribution',
            [CouponDistributionEvents::DISTRIBUTION_CHUNK => app(DistributionChunkHandler::class)],
            app(CouponEventLogService::class),
        );

        $this->assertSame(\App\Services\Coupon\Distribution\Messaging\ConsumeResult::DEAD_LETTER, $outcome);
        $this->assertDatabaseHas('coupon_event_logs', [
            'event_id' => $envelope->eventId,
            'status' => 'dead_lettered',
        ]);
    }

    public function test_audience_cap_truncates_fanout()
    {
        config()->set('coupon-distribution.chunk_size', 10);
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $users = User::factory()->count(3)->create();
        foreach ($users as $user) {
            CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);
        }

        app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual', null, 1
        );
        $this->pump();

        $run = \App\Models\CouponDistributionRun::query()
            ->where('coupon_id', $coupon->id)->firstOrFail();

        $this->assertSame(1, (int) $run->candidate_count);
        $this->assertSame(1, \App\Models\CouponDistributionRecipient::query()
            ->where('run_id', $run->id)->count());
    }

    public function test_multi_chunk_recall_is_complete()
    {
        config()->set('coupon-distribution.chunk_size', 2);
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $users = User::factory()->count(5)->create();
        foreach ($users as $user) {
            CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);
        }

        app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual'
        );
        $this->pump();

        $run = \App\Models\CouponDistributionRun::query()
            ->where('coupon_id', $coupon->id)->firstOrFail();

        $this->assertSame(5, (int) $run->candidate_count);
        $this->assertSame(5, (int) $run->notified_count);
        $this->assertSame('completed', $run->status->value);

        foreach ($users as $user) {
            $this->assertCount(1, $user->fresh()->notifications()->where('type', 'coupon.eligible')->get());
        }
    }

    public function test_completed_event_redelivery_is_noop_ack()
    {
        $envelope = CouponEventEnvelope::create(CouponDistributionEvents::USER_EVALUATE, 1, ['x' => 1]);
        $log = app(\App\Services\Coupon\Distribution\Observability\CouponEventLogService::class);
        $log->recordPublished($envelope);
        $log->markCompleted($envelope->eventId, 5);

        $called = new class {
            public int $calls = 0;

            public function handle($envelope)
            {
                $this->calls++;

                throw new \RuntimeException('must not run');
            }
        };
        $command = app(\App\Console\Commands\Coupons\ConsumeCouponQueueCommand::class);
        $outcome = $command->processOne(
            ['body' => $envelope->toJson(), 'headers' => ['x-attempt' => 2], 'redelivered' => true],
            'evaluation',
            [CouponDistributionEvents::USER_EVALUATE => $called],
            $log,
        );

        $this->assertSame(\App\Services\Coupon\Distribution\Messaging\ConsumeResult::ACK, $outcome);
        $this->assertSame(0, $called->calls);
    }

    public function test_created_active_coupon_enters_activation_plane()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);

        // CouponObserver::created fires CouponActivated for status=true.
        // The creation-time pass skips (no targeting yet); an explicit
        // activation signal with targeting present opens the run.
        event(new \App\Events\Coupons\CouponActivated($coupon->fresh('targeting')));

        $this->assertDatabaseHas('coupon_distribution_runs', [
            'coupon_id' => $coupon->id,
            'trigger_type' => 'coupon_activated',
        ]);
    }
}
