<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use App\Enums\CouponDistributionTriggerType;
use App\Events\AssignedCouponConsumed;
use App\Events\Coupons\CouponTargetingChanged;
use App\Listeners\Coupons\StartCouponDistribution;
use App\Listeners\SendUserCouponAvailableNotification;
use App\Models\CouponDistributionRun;
use App\Notifications\UserCouponAvailableNotification;
use App\Notifications\UserCouponUsedNotification;
use App\Services\Coupon\Distribution\Consumers\DistributionStartHandler;
use App\Services\Coupon\Distribution\Events\CouponDistributionEvents;
use App\Services\Coupon\Distribution\DistributionService;
use App\Services\Coupon\Distribution\Events\CouponEventEnvelope;
use App\Services\Coupon\Distribution\NonDistributableCouponException;
use App\Models\CouponOutbox;
use App\Services\Coupon\Distribution\Outbox\CouponOutboxService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Database\Repositories\CouponAssignmentRepository;
use Tests\TestCase;

/**
 * Unified 4-minute intentional business delay.
 *
 * Policy: coupon.available = +4 min, automatic targeting distribution = +4 min;
 * assignment / manual distribution / coupon.used = immediate.
 * Retry backoff, TTLs, leases, reservation windows and scheduler cadences are
 * separate mechanisms and are NOT asserted here (they must stay untouched).
 */
class CouponBusinessDelayTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(string $type = 'user'): User
    {
        return User::create([
            'name' => ucfirst($type) . ' User',
            'email' => $type . '-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => $type,
            'phone_number' => '01' . rand(100000000, 999999999),
        ]);
    }

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::withoutEvents(fn () => Coupon::create(array_merge([
            'code' => 'DLY-' . strtoupper(Str::random(6)),
            'name' => 'Delay coupon',
            'slug' => 'delay-' . strtolower(Str::random(6)),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ], $overrides)));
    }

    private function makeTargetedCoupon(): Coupon
    {
        $coupon = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => 'dynamic', 'rule_tree' => null]);

        return $coupon->fresh();
    }

    /** @test */
    public function assigned_notification_is_immediate_no_business_delay(): void
    {
        Event::fake([AssignedCouponConsumed::class, \App\Events\CouponAssigned::class]);

        $coupon = $this->makeCoupon();
        $user = $this->makeUser();

        app(CouponAssignmentRepository::class)->assignCoupon($coupon->id, [
            'user_id' => $user->id,
            'max_uses' => 1,
        ]);

        Event::assertDispatched(\App\Events\CouponAssigned::class);
        // The grant path never touches the delayed outbox.
        $this->assertSame(0, CouponOutbox::query()->count());
    }

    /** @test */
    public function public_availability_uses_four_minute_delay(): void
    {
        $this->assertSame(4, config('coupon-distribution.delay_minutes'));
        $this->assertSame(4, config('coupon-distribution.public_grace_minutes'));

        $user = $this->makeUser();
        $coupon = $this->makeCoupon(); // public: no assignments, no targeting
        $listener = app(SendUserCouponAvailableNotification::class);

        // Fresh coupon: too young to prove public.
        $this->assertFalse($listener->sendIfMaturePublic($coupon->fresh()));

        // Past the 4-minute window the sweep delivers exactly once.
        Carbon::setTestNow(now()->addMinutes(5));
        try {
            $this->artisan('coupons:detect-public');
            $this->assertDatabaseHas('notifications', [
                'notifiable_id' => $user->id,
                'type' => 'coupon.available',
            ]);
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @test */
    public function automatic_distribution_outbox_uses_four_minute_delay(): void
    {
        $this->assertSame(240, config('coupon-distribution.distribution_delay_seconds'));

        $coupon = $this->makeTargetedCoupon();
        (new StartCouponDistribution())->handle(new CouponTargetingChanged($coupon));

        $row = CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::DISTRIBUTION_START)
            ->latest('id')->firstOrFail();

        $wait = $row->available_at->diffInSeconds(Carbon::now());
        $this->assertGreaterThanOrEqual(235, $wait);
        $this->assertLessThanOrEqual(240, $wait);

        // The minutely sweep gates on available_at: not yet due.
        $this->assertSame(0, app(CouponOutboxService::class)->publishDue(10));

        // Past the window the same row publishes exactly once.
        Carbon::setTestNow(now()->addMinutes(5));
        try {
            $this->assertSame(1, app(CouponOutboxService::class)->publishDue(10));
        } finally {
            Carbon::setTestNow();
        }
    }

    /** @test */
    public function distribution_delay_is_config_driven_not_hardcoded(): void
    {
        Config::set('coupon-distribution.distribution_delay_seconds', 420);

        $coupon = $this->makeTargetedCoupon();
        (new StartCouponDistribution())->handle(new CouponTargetingChanged($coupon));

        $row = CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::DISTRIBUTION_START)
            ->latest('id')->firstOrFail();

        $wait = $row->available_at->diffInSeconds(Carbon::now());
        $this->assertGreaterThanOrEqual(415, $wait);
        $this->assertLessThanOrEqual(420, $wait);
    }

    /** @test */
    public function manual_distribution_is_immediate(): void
    {
        $coupon = $this->makeTargetedCoupon();

        $result = app(DistributionService::class)->startDistribution(
            $coupon,
            CouponDistributionTriggerType::MANUAL,
            'manual',
            'admin:1',
            10000,
        );

        $this->assertTrue($result['created']);
        $row = CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::DISTRIBUTION_START)
            ->latest('id')->firstOrFail();

        $this->assertFalse($row->available_at->isFuture());
    }

    /** @test */
    public function coupon_used_notification_is_immediate(): void
    {
        Notification::fake();

        $user = $this->makeUser();
        $coupon = $this->makeCoupon();
        $assignment = CouponAssignment::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'max_uses' => 1, 'used' => 0,
        ]);

        $order = Order::withoutEvents(fn () => Order::create([
            'user_id' => $user->id,
            'name' => 'Queue User',
            'user_phone' => '01000000000',
            'user_email' => 'queue@example.com',
            'address' => json_encode(['id' => 1]),
            'status' => 'pending',
            'payment_status' => 'pending',
            'total_price' => 100.00,
            'price' => 100.00,
        ]));

        event(new AssignedCouponConsumed($coupon, $assignment, $user, $order, 0, now()));

        Notification::assertSentTo($user, UserCouponUsedNotification::class);
        $this->assertSame(0, CouponOutbox::query()->count());
    }

    private function scheduleRun(Coupon $coupon): CouponDistributionRun
    {
        (new StartCouponDistribution())->handle(new CouponTargetingChanged($coupon));

        $row = CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::DISTRIBUTION_START)
            ->latest('id')->firstOrFail();

        return CouponDistributionRun::query()->findOrFail($row->payload['distribution_run_id']);
    }

    private function startEnvelope(CouponDistributionRun $run, Coupon $coupon): CouponEventEnvelope
    {
        return CouponEventEnvelope::create(
            CouponDistributionEvents::DISTRIBUTION_START,
            aggregateId: $coupon->getKey(),
            payload: [
                'run_id' => $run->getKey(),
                'coupon_id' => $coupon->getKey(),
                'tree_hash' => $run->tree_hash,
            ],
        );
    }

    /** @test */
    public function disabled_coupon_run_cancels_at_execution_inside_delay_window(): void
    {
        $coupon = $this->makeTargetedCoupon();
        $run = $this->scheduleRun($coupon);

        $coupon->update(['status' => false]);

        $result = app(DistributionStartHandler::class)->handle(
            $this->startEnvelope($run, $coupon->fresh())
        );

        $this->assertSame(0, $result['chunks']);
        $this->assertSame('cancelled', $run->fresh()->status->value);
    }

    /** @test */
    public function expired_coupon_run_cancels_at_execution_inside_delay_window(): void
    {
        $coupon = $this->makeTargetedCoupon();
        $run = $this->scheduleRun($coupon);

        $coupon->update(['end_date' => now()->subDay()->toDateString()]);

        $result = app(DistributionStartHandler::class)->handle(
            $this->startEnvelope($run, $coupon->fresh())
        );

        $this->assertSame(0, $result['chunks']);
        $this->assertSame('cancelled', $run->fresh()->status->value);
    }

    /** @test */
    public function targeting_drift_aborts_stale_run(): void
    {
        $coupon = $this->makeTargetedCoupon();
        $run = $this->scheduleRun($coupon);

        $coupon->targeting->update(['rule_tree' => [
            'operator' => 'AND',
            'rules' => [['type' => 'min_completed_orders', 'value' => 5]],
        ]]);

        $this->expectException(NonDistributableCouponException::class);
        app(DistributionStartHandler::class)->handle(
            $this->startEnvelope($run, $coupon->fresh())
        );
    }

    /** @test */
    public function duplicate_targeting_event_creates_single_delayed_row(): void
    {
        $coupon = $this->makeTargetedCoupon();
        $base = CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::DISTRIBUTION_START)->count();

        (new StartCouponDistribution())->handle(new CouponTargetingChanged($coupon));
        (new StartCouponDistribution())->handle(new CouponTargetingChanged($coupon->fresh()));

        $this->assertSame($base + 1, CouponOutbox::query()
            ->where('event_type', CouponDistributionEvents::DISTRIBUTION_START)->count());
    }

    /** @test */
    public function delay_comes_from_authoritative_config_without_direct_env_calls(): void
    {
        $this->assertSame(4, config('coupon-distribution.delay_minutes'));
        $this->assertSame(240, config('coupon-distribution.distribution_delay_seconds'));
        $this->assertSame(4, config('coupon-distribution.public_grace_minutes'));

        // env() is legal ONLY inside config files: none of the runtime
        // consumers may resolve the delay via env() directly.
        foreach ([
            app_path('Listeners/Coupons/StartCouponDistribution.php'),
            app_path('Listeners/SendUserCouponAvailableNotification.php'),
            app_path('Console/Commands/Coupons/DetectPublicCouponsCommand.php'),
        ] as $file) {
            $this->assertStringNotContainsString('env(', file_get_contents($file), $file);
        }
    }
}
