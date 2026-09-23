<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionRecipientStatus;
use App\Enums\CouponDistributionRunStatus;
use App\Enums\CouponDistributionTriggerType;
use App\Enums\CouponDistributionUserState as UserState;
use App\Enums\CouponOutboxStatus;
use App\Models\CouponDistributionRecipient;
use App\Models\CouponDistributionRun;
use App\Models\CouponDistributionUserState;
use App\Models\CouponEventLog;
use App\Models\CouponOutbox;
use App\Services\Coupon\Distribution\Consumers\DistributionStartHandler;
use App\Services\Coupon\Distribution\Consumers\NotificationRequestHandler;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Observability\CouponEventLogService;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Regression tests for the production-readiness audit
 * (COUPON_DISTRIBUTION_PRODUCTION_READINESS_AUDIT.md):
 * outbox lease reclaim (B1/M1), observability prune (M2), notify-pending
 * release on terminal drop (S2), terminal-run resurrection guard (S3).
 */
class ProductionReadinessRegressionTest extends TestCase
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

    private function envelope(): CouponEventEnvelope
    {
        return CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: 1,
            payload: ['run_id' => 1],
        );
    }

    public function test_stale_publishing_claim_is_reclaimed_by_sweep()
    {
        $service = app(CouponOutboxService::class);
        $row = $service->record($this->envelope());

        // Simulate a publisher that crashed holding the claim 10 min ago.
        CouponOutbox::query()->where('id', $row->id)->update([
            'status' => CouponOutboxStatus::PUBLISHING->value,
            'attempts' => 1,
            'updated_at' => now()->subMinutes(10),
        ]);

        $this->assertSame(1, $service->publishDue(100));
        $this->assertCount(1, $this->fake->published);

        $row->refresh();
        $this->assertSame(CouponOutboxStatus::PUBLISHED, $row->status);
    }

    public function test_fresh_publishing_claim_is_not_stolen()
    {
        $service = app(CouponOutboxService::class);
        $row = $service->record($this->envelope());

        // A live publisher claimed this row just now — the sweep and any
        // concurrent publisher must leave it alone (no double publish).
        CouponOutbox::query()->where('id', $row->id)->update([
            'status' => CouponOutboxStatus::PUBLISHING->value,
            'attempts' => 1,
            'updated_at' => now(),
        ]);

        $this->assertFalse($service->publishOne($row->event_id));
        $this->assertSame(0, $service->publishDue(100));
        $this->assertCount(0, $this->fake->published);

        $row->refresh();
        $this->assertSame(CouponOutboxStatus::PUBLISHING, $row->status);
    }

    public function test_prune_keeps_work_published_but_drops_observability_published()
    {
        $log = app(CouponEventLogService::class);

        $work = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START, 1, ['run_id' => 1]);
        $observed = CouponEventEnvelope::create(
            CouponDistributionEvents::BECAME_ELIGIBLE, 1, ['run_id' => 1]);

        $log->recordPublished($work);
        $log->recordPublished($observed);

        // Unknown/future types are fail-closed evidence, never pruned.
        $unknownId = (string) Str::uuid();
        \Illuminate\Support\Facades\DB::table('coupon_event_logs')->insert([
            'event_id' => $unknownId,
            'event_type' => 'coupon.future.hypothetical',
            'correlation_id' => (string) Str::uuid(),
            'status' => 'published',
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        CouponEventLog::query()->update([
            'created_at' => now()->subDays(100),
            'updated_at' => now()->subDays(100),
        ]);

        $this->artisan('coupons:prune-events', ['--days' => 90])->assertSuccessful();

        // stuck-pipeline evidence retained …
        $this->assertDatabaseHas('coupon_event_logs', ['event_id' => $work->eventId]);
        $this->assertDatabaseHas('coupon_event_logs', ['event_id' => $unknownId]);
        // … observability history pruned.
        $this->assertDatabaseMissing('coupon_event_logs', ['event_id' => $observed->eventId]);
    }

    public function test_notification_terminal_drop_releases_notify_pending_without_notifying()
    {        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        $treeHash = \App\Services\Coupon\Distribution\TreeHash::forRuleTree(
            $coupon->targeting->rule_tree, 'dynamic');

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual');
        /** @var CouponDistributionRun $run */
        $run = $result['run'];

        $recipient = CouponDistributionRecipient::create([
            'run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey(),
            'user_id' => $user->getKey(), 'tree_hash' => $treeHash,
            'status' => CouponDistributionRecipientStatus::ELIGIBLE,
        ]);
        CouponDistributionUserState::create([
            'coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey(),
            'tree_hash' => $treeHash, 'state' => UserState::NOTIFY_PENDING,
            'last_run_id' => $run->getKey(), 'last_recipient_id' => $recipient->getKey(),
        ]);

        // Coupon disabled mid-flight: run cancelled while the request is queued.
        $run->update(['status' => CouponDistributionRunStatus::CANCELLED]);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::NOTIFICATION_REQUESTED,
            aggregateId: $coupon->getKey(),
            payload: [
                'run_id' => $run->getKey(), 'recipient_id' => $recipient->getKey(),
                'coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey(),
                'tree_hash' => $treeHash,
            ],
            userId: $user->getKey(),
        );

        app(NotificationRequestHandler::class)->handle($envelope);

        // Silent: no notification row, recipient untouched …
        $this->assertSame(0, $user->fresh()->notifications()->where('type', 'coupon.eligible')->count());
        $this->assertSame(
            CouponDistributionRecipientStatus::ELIGIBLE,
            $recipient->fresh()->status);
        // … but the wedged marker is released so a future run re-evaluates.
        $this->assertSame(
            UserState::ELIGIBLE,
            CouponDistributionUserState::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $user->getKey())->first()->state);
    }

    public function test_start_handler_keeps_terminal_run_terminal()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual');
        /** @var CouponDistributionRun $run */
        $run = $result['run'];
        $run->update(['status' => CouponDistributionRunStatus::COMPLETED]);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: $coupon->getKey(),
            payload: [
                'run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey(),
                'tree_hash' => $run->tree_hash, 'trigger' => 'manual',
            ],
            distributionRunId: $run->getKey(),
            treeHash: $run->tree_hash,
        );

        $outcome = app(DistributionStartHandler::class)->handle($envelope);

        $this->assertSame(0, $outcome['chunks']);
        $this->assertSame(CouponDistributionRunStatus::COMPLETED, $run->fresh()->status);
        $this->assertSame(0, CouponDistributionRecipient::query()
            ->where('run_id', $run->getKey())->count());
    }

    public function test_notification_terminal_drop_never_downgrades_notified()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        $treeHash = \App\Services\Coupon\Distribution\TreeHash::forRuleTree(
            $coupon->targeting->rule_tree, 'dynamic');

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual');
        /** @var CouponDistributionRun $run */
        $run = $result['run'];

        $recipient = CouponDistributionRecipient::create([
            'run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey(),
            'user_id' => $user->getKey(), 'tree_hash' => $treeHash,
            'status' => CouponDistributionRecipientStatus::NOTIFIED,
            'notified_at' => now(),
        ]);
        CouponDistributionUserState::create([
            'coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey(),
            'tree_hash' => $treeHash, 'state' => UserState::NOTIFIED,
            'last_run_id' => $run->getKey(), 'last_recipient_id' => $recipient->getKey(),
            'notified_at' => now(),
        ]);

        $run->update(['status' => CouponDistributionRunStatus::CANCELLED]);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::NOTIFICATION_REQUESTED,
            aggregateId: $coupon->getKey(),
            payload: [
                'run_id' => $run->getKey(), 'recipient_id' => $recipient->getKey(),
                'coupon_id' => $coupon->getKey(), 'user_id' => $user->getKey(),
                'tree_hash' => $treeHash,
            ],
            userId: $user->getKey(),
        );

        app(NotificationRequestHandler::class)->handle($envelope);

        // Already-notified stays notified: the release path cannot manufacture
        // a duplicate notification on a later re-run.
        $this->assertSame(
            UserState::NOTIFIED,
            CouponDistributionUserState::query()
                ->where('coupon_id', $coupon->getKey())
                ->where('user_id', $user->getKey())->first()->state);
        $this->assertSame(0, $user->fresh()->notifications()->where('type', 'coupon.eligible')->count());
    }

    public function test_start_handler_keeps_failed_run_terminal()
    {
        $coupon = $this->createDynamicCoupon(['type' => 'min_completed_orders', 'value' => 0]);

        $result = app(DistributionService::class)->startDistribution(
            $coupon->fresh('targeting'), CouponDistributionTriggerType::MANUAL, 'manual');
        /** @var CouponDistributionRun $run */
        $run = $result['run'];
        $run->update(['status' => CouponDistributionRunStatus::FAILED]);

        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: $coupon->getKey(),
            payload: [
                'run_id' => $run->getKey(), 'coupon_id' => $coupon->getKey(),
                'tree_hash' => $run->tree_hash, 'trigger' => 'manual',
            ],
            distributionRunId: $run->getKey(),
            treeHash: $run->tree_hash,
        );

        $outcome = app(DistributionStartHandler::class)->handle($envelope);

        $this->assertSame(0, $outcome['chunks']);
        $this->assertSame(CouponDistributionRunStatus::FAILED, $run->fresh()->status);
        $this->assertSame(0, CouponDistributionRecipient::query()
            ->where('run_id', $run->getKey())->count());
    }

    public function test_consume_exits_gracefully_when_broker_down()
    {
        $transport = \Mockery::mock(
            \App\Services\Coupon\Distribution\Messaging\CouponEventTransport::class);
        $transport->shouldReceive('consume')->andThrow(
            new \App\Services\Coupon\Distribution\Messaging\BrokerUnreachableException('down'));
        $this->app->instance(
            \App\Services\Coupon\Distribution\Messaging\CouponEventTransport::class, $transport);

        // No traceback: one-line error + FAILURE exit so supervisors back off
        // while the outbox keeps events pending.
        $code = \Illuminate\Support\Facades\Artisan::call('coupon:consume', [
            '--queue' => 'evaluation', '--max-messages' => 1,
        ]);

        $this->assertSame(1, $code);
    }

    private function createDynamicCoupon(array $ruleTree): Coupon
    {
        $code = 'PRR-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Readiness Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => $ruleTree,
        ]);

        return $coupon;
    }
}
