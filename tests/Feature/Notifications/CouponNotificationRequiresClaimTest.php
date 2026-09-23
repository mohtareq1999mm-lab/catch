<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\AssignedCouponConsumed;
use App\Events\CouponAssigned;
use App\Events\CouponCreated;
use App\Notifications\AdminQueueJobFailedNotification;
use App\Notifications\UserCouponEligibleNotification;
use App\Services\Coupon\CouponClaimRequirement;
use App\Services\Coupon\Distribution\Messaging\CouponEventTransport;
use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use App\Services\Coupon\Distribution\Messaging\RabbitMqCouponEventTransport;
use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\CouponTargeting;

/**
 * requires_claim contract across the coupon notification lifecycle.
 *
 * Source of truth: coupon_targetings.require_claim (boolean). No targeting
 * row means no claim requirement (false). Every assertion below requires a
 * genuine JSON boolean — never "true", 1, or "1".
 */
class CouponNotificationRequiresClaimTest extends NotificationE2ETestCase
{
    // ---------- transport binding ----------

    public function test_transport_binding_resolves(): void
    {
        // Unit-test branch of the provider: serves the fake so distribution
        // tests run broker-less. The key assertion is that the interface
        // RESOLVES (the production bug was "not instantiable").
        $this->assertInstanceOf(
            FakeCouponEventTransport::class,
            app(CouponEventTransport::class)
        );

        // Production branch constructs the real AMQP transport (its
        // connection is lazy, so no broker is needed to prove the contract).
        $this->assertInstanceOf(
            CouponEventTransport::class,
            new RabbitMqCouponEventTransport()
        );
    }

    // ---------- source-of-truth helper ----------

    public function test_claim_requirement_helper_defaults(): void
    {
        $this->assertFalse(CouponClaimRequirement::forCoupon(null));

        $coupon = $this->createCoupon();
        $this->assertFalse(CouponClaimRequirement::forCoupon($coupon));

        $plain = $this->createCoupon();
        $this->createTargeting($plain, false);
        $this->assertFalse(CouponClaimRequirement::forCoupon($plain->fresh()));

        $claimable = $this->createCoupon();
        $this->createTargeting($claimable, true);
        $this->assertTrue(CouponClaimRequirement::forCoupon($claimable->fresh()));
    }

    // ---------- coupon.assigned without targeting (Phase 16) ----------

    public function test_assigned_without_targeting_carries_requires_claim_false(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCoupon(); // no targeting row
        $assignment = $this->createCouponAssignment($coupon, $user);

        event(new CouponAssigned($assignment));

        $notification = $this->assertDatabaseNotification(
            $user,
            'coupon.assigned',
            function ($n) use ($coupon, $assignment) {
                $this->assertSame($coupon->id, $n->data['resource_id']);
                $this->assertSame($assignment->id, $n->data['coupon_assignment_id']);
                $this->assertArrayHasKey('requires_claim', $n->data);
                $this->assertIsBool($n->data['requires_claim']);
                $this->assertFalse($n->data['requires_claim']);
            }
        );

        // Stored JSON must decode to a real boolean as well.
        $raw = DB::table('notifications')->where('id', $notification->id)->value('data');
        $this->assertFalse(json_decode((string) $raw, true)['requires_claim']);

        $broadcast = $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.assigned');
        $this->assertSame(false, $this->findRequiresClaim($broadcast['data']));
    }

    // ---------- coupon.assigned with targeting (Phase 17) ----------

    public function test_assigned_with_claim_targeting_carries_requires_claim_true(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCoupon();
        $this->createTargeting($coupon, true);
        $assignment = $this->createCouponAssignment($coupon, $user);

        event(new CouponAssigned($assignment));

        $this->assertDatabaseNotification(
            $user,
            'coupon.assigned',
            function ($n) {
                $this->assertIsBool($n->data['requires_claim']);
                $this->assertTrue($n->data['requires_claim']);
            }
        );

        $broadcast = $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.assigned');
        $this->assertSame(true, $this->findRequiresClaim($broadcast['data']));
    }

    // ---------- coupon.eligible (Phase 18) ----------

    public function test_eligible_carries_requires_claim_without_code_leak(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCoupon();
        $this->createTargeting($coupon, true);

        $user->notify(new UserCouponEligibleNotification($coupon->fresh(), 7, 'abc123'));

        $this->assertDatabaseNotification(
            $user,
            'coupon.eligible',
            function ($n) use ($coupon) {
                $this->assertSame($coupon->id, $n->data['coupon_id']);
                $this->assertIsBool($n->data['requires_claim']);
                $this->assertTrue($n->data['requires_claim']);
                // Confidentiality contract: no code, rules, or counters.
                $this->assertArrayNotHasKey('coupon_code', $n->data);
            }
        );

        $broadcast = $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.eligible');
        $this->assertSame(true, $this->findRequiresClaim($broadcast['data']));
    }

    public function test_eligible_without_targeting_carries_requires_claim_false(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCoupon();

        $payload = (new UserCouponEligibleNotification($coupon))->toDatabase($user);

        $this->assertArrayHasKey('requires_claim', $payload);
        $this->assertIsBool($payload['requires_claim']);
        $this->assertFalse($payload['requires_claim']);
    }

    // ---------- coupon.available (Phase 19) ----------

    public function test_available_carries_requires_claim_false_for_public_coupon(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCoupon();
        DB::table('coupons')->where('id', $coupon->id)
            ->update(['created_at' => now()->subMinutes(30)]);

        event(new CouponCreated($coupon->fresh()));

        $this->assertDatabaseNotification(
            $user,
            'coupon.available',
            function ($n) use ($coupon) {
                $this->assertSame($coupon->code, $n->data['coupon_code']);
                $this->assertIsBool($n->data['requires_claim']);
                $this->assertFalse($n->data['requires_claim']);
            }
        );

        $broadcast = $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.available');
        $this->assertSame(false, $this->findRequiresClaim($broadcast['data']));
    }

    // ---------- coupon.used (Phase 20) ----------

    public function test_used_carries_canonical_requires_claim_without_changing_usage(): void
    {
        $user = $this->createUser('user');
        $coupon = $this->createCoupon();
        $this->createTargeting($coupon, true);
        $assignment = $this->createCouponAssignment($coupon, $user);
        $order = $this->createOrder($user);

        event(new AssignedCouponConsumed($coupon, $assignment, $user, $order, 0, now()));

        $this->assertDatabaseNotification(
            $user,
            'coupon.used',
            function ($n) use ($coupon, $order) {
                $this->assertSame($coupon->id, $n->data['coupon_id']);
                $this->assertSame($order->id, $n->data['order_id']);
                $this->assertSame(0, $n->data['remaining_uses']);
                $this->assertIsBool($n->data['requires_claim']);
                $this->assertTrue($n->data['requires_claim']);
            }
        );

        $broadcast = $this->assertBroadcastTo('private-users.' . $user->id, 'coupon.used');
        $this->assertSame(true, $this->findRequiresClaim($broadcast['data']));
    }

    // ---------- UTF-8 admin alert regression (Phase 22) ----------

    public function test_queue_failure_admin_alert_builds_without_utf8_error(): void
    {
        $admin = $this->createUser('admin');

        $notification = new AdminQueueJobFailedNotification(
            'CallQueuedListener',
            'high',
            'Target [App\\Services\\Coupon\\Distribution\\Messaging\\CouponEventTransport] is not instantiable.'
        );

        $payload = $notification->toDatabase($admin);

        $this->assertIsString($payload['message']['ar']);
        $this->assertTrue(mb_check_encoding($payload['message']['ar'], 'UTF-8'));
        $this->assertTrue(mb_check_encoding($payload['message']['en'], 'UTF-8'));
        // Payload must JSON-serialize cleanly for the database channel.
        $this->assertNotFalse(json_encode($payload));
    }

    // ---------- helpers ----------

    private function createTargeting($coupon, bool $requireClaim)
    {
        return CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => $requireClaim,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);
    }

    /**
     * Recursively locate requires_claim inside a recorded Pusher payload
     * (array or JSON string), asserting genuine boolean type at the end.
     */
    private function findRequiresClaim(mixed $payload): mixed
    {
        if (is_string($payload)) {
            $payload = json_decode($payload, true);
        }

        $found = $this->walkForKey($payload, 'requires_claim');

        $this->assertTrue(is_bool($found), 'requires_claim must be a genuine boolean in the Pusher payload.');

        return $found;
    }

    private function walkForKey(mixed $node, string $key): mixed
    {
        if (is_array($node)) {
            if (array_key_exists($key, $node)) {
                return $node[$key];
            }

            foreach ($node as $value) {
                $found = $this->walkForKey($value, $key);

                if (is_bool($found)) {
                    return $found;
                }
            }
        }

        return null;
    }
}
