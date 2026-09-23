<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use App\Enums\QueueName;
use App\Listeners\SendUserCouponAssignedNotification;
use App\Listeners\SendUserCouponAvailableNotification;
use App\Listeners\SendUserCouponUsedNotification;
use App\Notifications\UserCouponAssignedNotification;
use App\Notifications\UserCouponAvailableNotification;
use App\Notifications\UserCouponEligibleNotification;
use App\Notifications\UserCouponUsedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Coupon notification queue routing is environment-driven.
 *
 * Logical HIGH → config('queue.queues.high') → env('QUEUE_HIGH').
 * No production queue name may appear in application code; changing the
 * configured value must reroute all four notifications without PHP changes.
 * (Eligible-notification coverage is the gap filled here — Assigned,
 * Available and Used are additionally covered in NotificationQueueTest.)
 */
class CouponNotificationQueueTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): User
    {
        return User::create([
            'name' => 'Queue User',
            'email' => 'queue-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01' . rand(100000000, 999999999),
        ]);
    }

    private function makeCoupon(): Coupon
    {
        return Coupon::withoutEvents(fn () => Coupon::create([
            'code' => 'Q-' . strtoupper(Str::random(6)),
            'name' => 'Queue coupon',
            'slug' => 'queue-' . strtolower(Str::random(6)),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ]));
    }

    private function makeAssignment(Coupon $coupon, User $user): CouponAssignment
    {
        return CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
            'used' => 0,
        ]);
    }

    /** @test */
    public function queue_names_resolve_from_env_backed_config_with_custom_names(): void
    {
        Config::set('queue.queues.high', 'meem-high');
        Config::set('queue.queues.medium', 'meem-medium');

        $this->assertSame('meem-high', config('queue.queues.high'));
        $this->assertSame('meem-high', QueueName::high());
        $this->assertSame('meem-medium', config('queue.queues.medium'));
        $this->assertSame('meem-medium', QueueName::medium());

        // Neutral local defaults remain the documented fallback.
        Config::set('queue.queues.high', 'high');
        Config::set('queue.queues.medium', 'medium');
        $this->assertSame('high', QueueName::high());
        $this->assertSame('medium', QueueName::medium());
    }

    /** @test */
    public function all_four_coupon_notifications_use_configured_high_queue(): void
    {
        Config::set('queue.queues.high', 'custom-high-xyz');

        $user = $this->makeUser();
        $coupon = $this->makeCoupon();
        $assignment = $this->makeAssignment($coupon, $user);

        $notifications = [
            'assigned' => new UserCouponAssignedNotification($assignment),
            'available' => new UserCouponAvailableNotification($coupon),
            'eligible' => new UserCouponEligibleNotification($coupon),
            'used' => new UserCouponUsedNotification($coupon, $assignment, $user, new Order(), 0, now()),
        ];

        foreach ($notifications as $key => $notification) {
            $this->assertInstanceOf(ShouldQueue::class, $notification, $key);
            $this->assertSame(config('queue.queues.high'), $notification->queue, $key);
            $this->assertSame('custom-high-xyz', $notification->queue, $key);
        }
    }

    /** @test */
    public function coupon_listeners_route_via_configured_high_queue(): void
    {
        Config::set('queue.queues.high', 'custom-high-xyz');

        $this->assertSame('custom-high-xyz', (new SendUserCouponAssignedNotification())->viaQueue());
        $this->assertSame('custom-high-xyz', (new SendUserCouponAvailableNotification())->viaQueue());
        $this->assertSame('custom-high-xyz', (new SendUserCouponUsedNotification())->viaQueue());
    }

    /** @test */
    public function dispatched_coupon_notification_lands_on_configured_queue_and_executes(): void
    {
        Config::set('queue.default', 'database');
        Config::set('queue.queues.high', 'rt-high-xyz');

        $user = $this->makeUser();
        $coupon = $this->makeCoupon();

        $user->notify(new UserCouponAvailableNotification($coupon));

        // Routing proof: the job row carries the configured physical name.
        $this->assertDatabaseHas('jobs', ['queue' => 'rt-high-xyz']);

        // Execution proof: one worker pass persists the durable DB notification.
        // (FCM re-queues as a nested job without executing; broadcast uses log driver.)
        $this->artisan('queue:work', ['--once' => true, '--queue' => 'rt-high-xyz']);

        $this->assertDatabaseHas('notifications', [
            'notifiable_id' => $user->id,
            // databaseType() carries broadcastType (coupon.available), not the FQCN.
            'type' => 'coupon.available',
        ]);
    }
}
