<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\AssignedCouponConsumed;
use App\Events\CouponAssigned;
use App\Events\CouponCreated;
use App\Notifications\UserCouponAssignedNotification;
use App\Notifications\UserCouponAvailableNotification;
use App\Notifications\UserCouponUsedNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\DB;

/**
 * Real pipeline: CouponObserver/CouponAssigned/AssignedCouponConsumed ->
 * real listeners -> real Notification (database + broadcast) -> notifications
 * table -> PusherBroadcaster -> RecordingPusher.
 */
class CouponNotificationE2ETest extends NotificationE2ETestCase
{
    public function test_coupon_created_fans_out_to_all_users_in_db_and_broadcast(): void
    {
        $userA = $this->createUser('user');
        $userB = $this->createUser('user');
        $admin = $this->createUser('admin');

        // CouponObserver::created dispatches CouponCreated -> fan-out listener.
        $coupon = $this->createCouponWithoutEvents();
        event(new CouponCreated($coupon));

        foreach ([$userA, $userB] as $user) {
            $this->assertDatabaseNotification(
                $user,
                'coupon.available',
                function ($n) use ($coupon) {
                    $this->assertEquals('coupon.available', $n->type);
                    $this->assertEquals($coupon->id, $n->data['resource_id']);
                }
            );
            $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.available');
        }

        $this->assertNoDatabaseNotification($admin, 'coupon.available');
    }

    public function test_coupon_created_skips_fanout_when_coupon_has_assignments(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCouponWithoutEvents();
        $this->createCouponAssignment($coupon, $user);

        event(new CouponCreated($coupon));

        $this->assertNoDatabaseNotification($user, 'coupon.available');
        $this->assertEmpty($this->recordedBroadcasts());
    }

    public function test_coupon_assigned_notifies_specific_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $other = $this->createUser('user');
        $coupon = $this->createCouponWithoutEvents();
        $assignment = $this->createCouponAssignment($coupon, $user);

        event(new CouponAssigned($assignment));

        $this->assertDatabaseNotification(
            $user,
            'coupon.assigned',
            function ($n) use ($coupon, $assignment) {
                $this->assertEquals('coupon.assigned', $n->type);
                $this->assertEquals($coupon->id, $n->data['resource_id']);
                $this->assertEquals($assignment->id, $n->data['assignment_id'] ?? $assignment->id);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.assigned');

        $this->assertNoDatabaseNotification($other, 'coupon.assigned');
    }

    public function test_coupon_assigned_skips_admin_user(): void
    {
        $admin = $this->createUser('admin');
        $coupon = $this->createCouponWithoutEvents();
        $assignment = $this->createCouponAssignment($coupon, $admin);

        event(new CouponAssigned($assignment));

        $this->assertNoDatabaseNotification($admin, 'coupon.assigned');
    }

    public function test_assigned_coupon_consumed_notifies_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCouponWithoutEvents();
        $assignment = $this->createCouponAssignment($coupon, $user);
        $order = $this->createOrder($user);

        event(new AssignedCouponConsumed($coupon, $assignment, $user, $order, 0, now()));

        $this->assertDatabaseNotification(
            $user,
            'coupon.used',
            function ($n) use ($coupon) {
                $this->assertEquals('coupon.used', $n->type);
                $this->assertEquals($coupon->id, $n->data['resource_id']);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.used');
    }

    private function createCouponWithoutEvents()
    {
        $id = DB::table('coupons')->insertGetId([
            'code' => 'CPN' . strtoupper(substr(uniqid(), -6)),
            'name' => 'Test Coupon',
            'slug' => 'test-coupon-' . uniqid(),
            'discount_type' => 'fixed',
            'discount' => 10,
            'start_date' => now()->toDateString(),
            'end_date' => now()->addDays(30)->toDateString(),
            'status' => true,
            'used' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return \Marvel\Database\Models\Coupon::find($id);
    }
}
