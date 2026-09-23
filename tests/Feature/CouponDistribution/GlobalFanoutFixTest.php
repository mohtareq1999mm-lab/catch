<?php

namespace Tests\Feature\CouponDistribution;

use App\Events\CouponCreated;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;
use Tests\TestCase;

class GlobalFanoutFixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        NullFcmChannel::reset();
        Notification::extend('fcm', fn () => new NullFcmChannel());
    }

    private function createCoupon(): Coupon
    {
        $code = 'FAN-'.Str::random(8);

        return Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Fanout Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
    }

    public function test_targeted_coupon_never_enters_global_fanout()
    {
        $users = User::factory()->count(3)->create();
        $coupon = $this->createCoupon();
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);

        // Creation-time fan-out predates targeting (pre-existing race, kept
        // for public coupons). Clear it: this test proves the SEND-TIME
        // skip for targeted coupons.
        \Illuminate\Notifications\DatabaseNotification::query()->delete();
        NullFcmChannel::reset();

        // Simulate the queued global listener AFTER targeting exists.
        app(\App\Listeners\SendUserCouponAvailableNotification::class)
            ->handle(new CouponCreated($coupon->fresh()));

        foreach ($users as $user) {
            $this->assertSame(
                0,
                $user->fresh()->notifications()->where('type', 'coupon.available')->count()
            );
        }

        $this->assertSame([], NullFcmChannel::$sent);
    }

    public function test_assignment_mode_coupon_never_enters_global_fanout()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'assignment', 'require_claim' => false,
            'rule_tree' => null,
        ]);

        \Illuminate\Notifications\DatabaseNotification::query()->delete();
        NullFcmChannel::reset();

        app(\App\Listeners\SendUserCouponAvailableNotification::class)
            ->handle(new CouponCreated($coupon->fresh()));

        $this->assertSame(0, $user->fresh()->notifications()->where('type', 'coupon.available')->count());
    }

    public function test_public_coupon_keeps_global_behavior()
    {
        $users = User::factory()->count(2)->create();
        $coupon = $this->createCoupon();
        // Mature past the creation grace: proven public (query builder —
        // created_at is not fillable and must not trip model validation).
        \Illuminate\Support\Facades\DB::table('coupons')->where('id', $coupon->id)
            ->update(['created_at' => now()->subMinutes(30)]);

        \Illuminate\Notifications\DatabaseNotification::query()->delete();

        app(\App\Listeners\SendUserCouponAvailableNotification::class)
            ->handle(new CouponCreated($coupon->fresh()));

        foreach ($users as $user) {
            $this->assertSame(
                1,
                $user->fresh()->notifications()->where('type', 'coupon.available')->count()
            );
        }
    }

    public function test_fresh_public_coupon_defers_to_sweep()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        app(\App\Listeners\SendUserCouponAvailableNotification::class)
            ->handle(new CouponCreated($coupon->fresh()));

        // Too fresh to prove public: no immediate fan-out (no leak window).
        $this->assertSame(0, $user->fresh()->notifications()->where('type', 'coupon.available')->count());

        // The sweep delivers it once mature.
        \Illuminate\Support\Facades\DB::table('coupons')->where('id', $coupon->id)
            ->update(['created_at' => now()->subMinutes(30)]);
        \Illuminate\Support\Facades\Artisan::call('coupons:detect-public');

        $this->assertSame(1, $user->fresh()->notifications()->where('type', 'coupon.available')->count());

        // Second sweep does not re-announce.
        \Illuminate\Support\Facades\Artisan::call('coupons:detect-public');
        $this->assertSame(1, $user->fresh()->notifications()->where('type', 'coupon.available')->count());
    }
}
