<?php

namespace Tests\Feature\CouponDistribution;

use App\Enums\CouponDistributionRecipientStatus;
use App\Enums\CouponDistributionTriggerType;
use App\Enums\CouponDistributionUserState as UserState;
use App\Models\CouponDistributionRecipient;
use App\Models\CouponDistributionRun;
use App\Models\CouponDistributionUserState;
use App\Models\CouponOutbox;
use App\Services\Coupon\Distribution\Consumers\NotificationRequestHandler;
use App\Services\Coupon\Distribution\DistributionRunService;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\EligibilityTransitionService;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use App\Services\Coupon\Distribution\TreeHash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Real-MySQL concurrency probes. Each `test_op_*` performs ONE racy
 * operation and exits; an external driver launches N processes in parallel
 * against a shared seed (NO RefreshDatabase — processes share the DB).
 * Skipped unless DB_CONNECTION=mysql. Every op is idempotent-safe to
 * re-run; `test_verify_*` asserts convergence; seed/cleanup are explicit.
 */
class MySqlConcurrencyTest extends TestCase
{
    public const CODE = 'MYSQL-CONC-1';
    public const CODE2 = 'MYSQL-CONC-2';

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('MySQL-only concurrency probe.');
        }

        $this->app->singleton(FakeCouponEventTransport::class, fn () => new FakeCouponEventTransport());
    }

    private function seedCoupon(string $code): Coupon
    {
        $coupon = Coupon::query()->where('code', $code)->first();
        if ($coupon) {
            return $coupon->fresh('targeting');
        }
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'MySQL Conc Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);

        return $coupon->fresh('targeting');
    }

    private function seedUser(string $email): User
    {
        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            $user = User::factory()->create(['email' => $email]);
        }
        CustomerMetrics::updateOrCreate(['user_id' => $user->id], ['completed_orders' => 5]);

        return $user;
    }

    public function test_seed_shared_fixture()
    {
        $coupon = $this->seedCoupon(self::CODE);
        for ($i = 1; $i <= 10; $i++) {
            $this->seedUser("conc{$i}@test.local");
        }
        // Notify-scenario coupon/run are seeded separately (test_seed_notify_fixture).
        echo "SEED coupon={$coupon->id}".PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_op_duplicate_distribute()
    {
        $coupon = $this->seedCoupon(self::CODE);
        $result = app(DistributionService::class)->startDistribution(
            $coupon, CouponDistributionTriggerType::MANUAL, 'manual');
        echo 'DISTRIBUTE created='.var_export($result['created'], true).' run='.$result['run']->getKey().PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_verify_single_live_run()
    {
        $coupon = Coupon::query()->where('code', self::CODE)->firstOrFail();
        $tree = TreeHash::forRuleTree($coupon->targeting->rule_tree, 'dynamic');
        $key = app(DistributionRunService::class)->dedupeKey(
            $coupon->id, $tree, CouponDistributionTriggerType::MANUAL, 'manual');
        $count = CouponDistributionRun::query()->where('dedupe_key', $key)->count();
        echo "RUNS with dedupe_key={$count}".PHP_EOL;
        $this->assertSame(1, $count);
    }

    public function test_seed_evaluate_fixture()
    {
        $coupon = $this->seedCoupon(self::CODE);
        $run = CouponDistributionRun::query()
            ->where('coupon_id', $coupon->id)->orderBy('id')->firstOrFail();
        $user = $this->seedUser('conc1@test.local');
        CouponDistributionRecipient::query()->firstOrCreate(
            ['run_id' => $run->id, 'user_id' => $user->id],
            ['coupon_id' => $coupon->id, 'tree_hash' => $run->tree_hash,
             'status' => CouponDistributionRecipientStatus::DISCOVERED]);
        echo "EVALFIX run={$run->id} user={$user->id}".PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_op_evaluate_same_user()
    {
        $coupon = $this->seedCoupon(self::CODE);
        $run = CouponDistributionRun::query()->where('coupon_id', $coupon->id)->orderBy('id')->firstOrFail();
        $user = User::query()->where('email', 'conc1@test.local')->firstOrFail();
        $recipient = CouponDistributionRecipient::query()
            ->where('run_id', $run->id)->where('user_id', $user->id)->firstOrFail();
        $cause = CouponEventEnvelope::create(CouponDistributionEvents::USER_EVALUATE,
            $coupon->id, ['run_id' => $run->id], userId: $user->id);
        $out = app(EligibilityTransitionService::class)->evaluate(
            $coupon, $user, $run, $recipient, $cause, $run->tree_hash);
        echo 'EVALUATE outcome='.$out['outcome'].PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_verify_single_transition()
    {
        $coupon = Coupon::query()->where('code', self::CODE)->firstOrFail();
        $user = User::query()->where('email', 'conc1@test.local')->firstOrFail();
        $states = CouponDistributionUserState::query()
            ->where('coupon_id', $coupon->id)->where('user_id', $user->id)->count();
        $reqs = CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::NOTIFICATION_REQUESTED)->count();
        echo "STATES={$states} NOTIF_REQUESTS={$reqs}".PHP_EOL;
        $this->assertSame(1, $states);
        $this->assertSame(1, $reqs);
    }

    public function test_seed_notify_fixture()
    {
        $coupon = $this->seedCoupon(self::CODE);
        $run = CouponDistributionRun::query()->where('coupon_id', $coupon->id)->orderBy('id')->firstOrFail();
        $user = User::query()->where('email', 'conc1@test.local')->firstOrFail();
        CouponDistributionRecipient::query()
            ->where('run_id', $run->id)->where('user_id', $user->id)
            ->update(['status' => CouponDistributionRecipientStatus::ELIGIBLE->value]);
        CouponDistributionUserState::query()->updateOrCreate(
            ['coupon_id' => $coupon->id, 'user_id' => $user->id],
            ['tree_hash' => $run->tree_hash, 'state' => UserState::NOTIFY_PENDING,
             'last_run_id' => $run->id]);
        echo 'NOTIFFIX armed'.PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_op_notify_same_user()
    {
        $coupon = $this->seedCoupon(self::CODE);
        $run = CouponDistributionRun::query()->where('coupon_id', $coupon->id)->orderBy('id')->firstOrFail();
        $user = User::query()->where('email', 'conc1@test.local')->firstOrFail();
        $recipient = CouponDistributionRecipient::query()
            ->where('run_id', $run->id)->where('user_id', $user->id)->firstOrFail();
        $envelope = CouponEventEnvelope::create(
            CouponDistributionEvents::NOTIFICATION_REQUESTED, $coupon->id,
            ['run_id' => $run->id, 'recipient_id' => $recipient->id, 'coupon_id' => $coupon->id,
             'user_id' => $user->id, 'tree_hash' => $run->tree_hash], userId: $user->id);
        app(NotificationRequestHandler::class)->handle($envelope);
        echo 'NOTIFY handled'.PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_verify_single_notification()
    {
        $user = User::query()->where('email', 'conc1@test.local')->firstOrFail();
        $n = $user->notifications()->where('type', 'coupon.eligible')->count();
        echo "DB_NOTIFICATIONS={$n}".PHP_EOL;
        $this->assertSame(1, $n);
    }

    public function test_seed_publish_fixture()
    {
        // Fixed probe id so parallel procs contend on the same row.
        $probeId = 'conc-probe-event';
        CouponOutbox::query()->where('event_id', $probeId)->delete();
        $probe = CouponEventEnvelope::create(CouponDistributionEvents::DISTRIBUTION_START, 1, ['run_id' => 1]);
        $probe->eventId = $probeId;
        app(CouponOutboxService::class)->record($probe);
        echo 'PUBFIX armed'.PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_op_publish_same_event()
    {
        $ok = app(CouponOutboxService::class)->publishOne('conc-probe-event');
        echo 'PUBLISH ok='.var_export($ok, true).PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_verify_single_publish()
    {
        $row = CouponOutbox::query()->where('event_id', 'conc-probe-event')->firstOrFail();
        echo "ATTEMPTS={$row->attempts} STATUS={$row->status->value}".PHP_EOL;
        $this->assertSame(1, (int) $row->attempts);
        $this->assertSame('published', $row->status->value);
    }

    public function test_seed_finish_fixture()
    {
        $coupon = $this->seedCoupon(self::CODE2);
        [$run] = app(DistributionRunService::class)->startOrJoin(
            $coupon->id, TreeHash::forRuleTree($coupon->targeting->rule_tree, 'dynamic'),
            CouponDistributionTriggerType::MANUAL, 'finish-probe');
        for ($i = 2; $i <= 4; $i++) {
            $user = $this->seedUser("conc{$i}@test.local");
            CouponDistributionRecipient::query()->updateOrCreate(
                ['run_id' => $run->id, 'user_id' => $user->id],
                ['coupon_id' => $coupon->id, 'tree_hash' => $run->tree_hash,
                 'status' => CouponDistributionRecipientStatus::NOTIFIED, 'notified_at' => now()]);
        }
        echo 'FINFIX run='.$run->id.PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_op_finish_same_run()
    {
        $coupon = Coupon::query()->where('code', self::CODE2)->firstOrFail();
        $run = CouponDistributionRun::query()->where('coupon_id', $coupon->id)->orderByDesc('id')->firstOrFail();
        $cause = CouponEventEnvelope::create(CouponDistributionEvents::EVALUATION_COMPLETED,
            $coupon->id, ['run_id' => $run->id]);
        app(DistributionRunService::class)->maybeFinishRun($run, $cause);
        echo 'FINISH status='.$run->fresh()->status->value.PHP_EOL;
        $this->assertTrue(true);
    }

    public function test_verify_finish_converged()
    {
        $coupon = Coupon::query()->where('code', self::CODE2)->firstOrFail();
        $run = CouponDistributionRun::query()->where('coupon_id', $coupon->id)->orderByDesc('id')->firstOrFail();
        $rec = app(DistributionRunService::class)->reconcile($run->fresh());
        $run->refresh();
        echo 'STATUS='.$run->status->value.' '.json_encode($rec).PHP_EOL;
        $this->assertSame('completed', $run->status->value);
        $this->assertSame(3, (int) $run->notified_count);
        $this->assertSame(0, (int) $run->failed_count);
    }

    public function test_cleanup_concurrency_fixtures()
    {
        foreach ([self::CODE, self::CODE2] as $code) {
            $coupon = Coupon::query()->where('code', $code)->first();
            if (! $coupon) {
                continue;
            }
            $runIds = CouponDistributionRun::query()->where('coupon_id', $coupon->id)->pluck('id');
            CouponDistributionRecipient::query()->whereIn('run_id', $runIds)->delete();
            CouponDistributionRun::query()->where('coupon_id', $coupon->id)->delete();
            CouponDistributionUserState::query()->where('coupon_id', $coupon->id)->delete();
            CouponOutbox::query()->where('aggregate_id', (string) $coupon->id)->delete();
            CouponTargeting::query()->where('coupon_id', $coupon->id)->delete();
            $coupon->delete();
        }
        CouponOutbox::query()->where('event_id', 'conc-probe-event')->delete();
        for ($i = 1; $i <= 10; $i++) {
            $user = User::query()->where('email', "conc{$i}@test.local")->first();
            if ($user) {
                DB::table('notifications')->where('notifiable_type', User::class)->where('notifiable_id', $user->id)->delete();
                CustomerMetrics::query()->where('user_id', $user->id)->delete();
                $user->delete();
            }
        }
        echo 'CLEANED'.PHP_EOL;
        $this->assertTrue(true);
    }
}
