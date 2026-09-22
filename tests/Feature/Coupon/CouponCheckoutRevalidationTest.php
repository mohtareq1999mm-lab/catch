<?php

namespace Tests\Feature\Coupon;

use App\Exceptions\CouponConsumptionException;
use App\Services\Coupon\CouponOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponUsage;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\Transaction;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Apply/checkout/payment enforcement of the targeting modes + rule trees.
 *
 * Proves the checkout-revalidation fix: dynamic rules are enforced at
 * apply/checkout/payment (not just claim), and assignment_and/or_dynamic
 * behave consistently across engine, orchestrator, and consumption.
 */
class CouponCheckoutRevalidationTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private User $otherUser;

    private Governorate $riyadh;

    private Governorate $jeddah;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        $this->user = User::factory()->create(['type' => 'user']);
        $this->otherUser = User::factory()->create(['type' => 'user']);

        $country = Country::create(['name' => 'Testland']);
        $this->riyadh = Governorate::create(['country_id' => $country->id, 'name' => 'Riyadh', 'status' => true]);
        $this->jeddah = Governorate::create(['country_id' => $country->id, 'name' => 'Jeddah', 'status' => true]);
    }

    private function createCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Revalidation Coupon',
            'slug' => 'reval-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));

        $coupon->update(['code' => $code]);

        return $coupon->fresh();
    }

    private function target(Coupon $coupon, string $mode, ?array $tree): void
    {
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => $mode,
            'rule_tree' => $tree,
        ]);
    }

    private function assign(Coupon $coupon, User $user, array $overrides = []): CouponAssignment
    {
        return CouponAssignment::create(array_merge([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'max_uses' => 1,
            'used' => 0,
            'assigned_at' => now(),
            'expires_at' => null,
        ], $overrides));
    }

    private function completeOrder(User $user, Coupon $coupon, ?int $governorateId = null): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'name' => 'Revalidation Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'governorate_id' => $governorateId,
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'completed',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 100,
            'invoice_id' => 'INV-REVAL-' . Str::random(8),
        ]);

        app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'completed', $order->id);

        return $order->fresh();
    }

    // =====================================================================
    // Dynamic revalidation at apply/checkout
    // =====================================================================

    /** @test */
    public function dynamic_rule_true_at_apply_false_at_checkout_is_rejected(): void
    {
        $coupon = $this->createCoupon('REVALDYN');
        $this->target($coupon, 'dynamic', ['type' => 'min_completed_orders', 'value' => 3]);

        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 5]);
        $apply = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertTrue($apply['valid'], 'eligible at apply time');

        // Metrics change between apply and checkout (new rebuild).
        CustomerMetrics::where('user_id', $this->user->id)->update(['completed_orders' => 0]);

        $checkout = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertFalse($checkout['valid']);
        $this->assertSame('not_eligible', $checkout['reason']);
    }

    /** @test */
    public function dynamic_mode_skips_assignment_gate_but_enforces_tree(): void
    {
        $coupon = $this->createCoupon('DYNSKIP');
        $this->target($coupon, 'dynamic', ['type' => 'min_completed_orders', 'value' => 1]);
        // Assignment rows exist for ANOTHER user only.
        $this->assign($coupon, $this->otherUser);

        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 2]);

        $result = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertTrue($result['valid'], 'unassigned user passes dynamic apply');

        CustomerMetrics::where('user_id', $this->user->id)->update(['completed_orders' => 0]);
        $failed = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertFalse($failed['valid']);
        $this->assertSame('not_eligible', $failed['reason']);
    }

    /** @test */
    public function area_rule_defers_at_apply_and_enforces_at_checkout(): void
    {
        $coupon = $this->createCoupon('REVALAREA');
        $this->target($coupon, 'dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id]]);

        $apply = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertTrue($apply['valid'], 'apply without area defers');

        $wrong = CouponOrchestrator::validate($coupon, $this->user, null, ['governorate_id' => $this->jeddah->id]);
        $this->assertFalse($wrong['valid']);
        $this->assertSame('not_eligible', $wrong['reason']);

        $right = CouponOrchestrator::validate($coupon, $this->user, null, ['governorate_id' => $this->riyadh->id]);
        $this->assertTrue($right['valid']);
    }

    // =====================================================================
    // assignment_and_dynamic
    // =====================================================================

    /** @test */
    public function and_mode_requires_both_assignment_and_tree(): void
    {
        $coupon = $this->createCoupon('REVALAND');
        $this->target($coupon, 'assignment_and_dynamic', ['type' => 'min_completed_orders', 'value' => 3]);
        $this->assign($coupon, $this->user);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 5]);

        $this->assertTrue(CouponOrchestrator::validate($coupon, $this->user)['valid']);

        CustomerMetrics::where('user_id', $this->user->id)->update(['completed_orders' => 0]);
        $failed = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertFalse($failed['valid']);
        $this->assertSame('not_eligible', $failed['reason']);

        // Unassigned user fails even with passing tree.
        CustomerMetrics::create(['user_id' => $this->otherUser->id, 'completed_orders' => 9]);
        $unassigned = CouponOrchestrator::validate($coupon, $this->otherUser);
        $this->assertFalse($unassigned['valid']);
        $this->assertSame('not_assigned', $unassigned['reason']);
    }

    // =====================================================================
    // assignment_or_dynamic
    // =====================================================================

    /** @test */
    public function or_mode_passes_on_assignment_alone(): void
    {
        $coupon = $this->createCoupon('REVALOR1');
        $this->target($coupon, 'assignment_or_dynamic', ['type' => 'min_completed_orders', 'value' => 99]);
        $this->assign($coupon, $this->user);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 0]);

        $this->assertTrue(CouponOrchestrator::validate($coupon, $this->user)['valid']);
    }

    /** @test */
    public function or_mode_passes_on_tree_alone_for_unassigned_user(): void
    {
        $coupon = $this->createCoupon('REVALOR2');
        $this->target($coupon, 'assignment_or_dynamic', ['type' => 'min_completed_orders', 'value' => 2]);
        $this->assign($coupon, $this->otherUser);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 5]);

        $this->assertTrue(CouponOrchestrator::validate($coupon, $this->user)['valid']);
    }

    /** @test */
    public function or_mode_fails_when_neither_path_passes(): void
    {
        $coupon = $this->createCoupon('REVALOR3');
        $this->target($coupon, 'assignment_or_dynamic', ['type' => 'min_completed_orders', 'value' => 99]);
        $this->assign($coupon, $this->otherUser);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 0]);

        $result = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertFalse($result['valid']);
        $this->assertSame('not_assigned', $result['reason']);
    }

    /** @test */
    public function or_mode_unassigned_user_completes_payment_via_public_path(): void
    {
        $coupon = $this->createCoupon('REVALORPAY');
        $this->target($coupon, 'assignment_or_dynamic', ['type' => 'min_completed_orders', 'value' => 1]);
        $this->assign($coupon, $this->otherUser);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 3]);

        $order = $this->completeOrder($this->user, $coupon);

        $this->assertSame('completed', $order->status);
        $this->assertDatabaseHas('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);
        $this->assertSame(1, $coupon->fresh()->used);
    }

    // =====================================================================
    // Consumption-path guards
    // =====================================================================

    /** @test */
    public function assignment_mode_still_rejects_unassigned_user_at_payment(): void
    {
        $coupon = $this->createCoupon('REVALASSIGN');
        $this->target($coupon, 'assignment', null);
        $this->assign($coupon, $this->otherUser);

        try {
            $this->completeOrder($this->user, $coupon);
            $this->fail('Unassigned completion must throw on assignment-mode coupons.');
        } catch (CouponConsumptionException $e) {
            // Deterministic path: with no live reservation, the payment-time
            // revalidation gate (Orchestrator assignment check) fires first
            // with NOT_ELIGIBLE; the NOT_ASSIGNED guard in recordCouponUsage
            // remains as defense in depth behind it.
            $this->assertSame(CouponConsumptionException::REASON_NOT_ELIGIBLE, $e->reason);
        }

        $this->assertDatabaseMissing('coupon_usages', [
            'coupon_id' => $coupon->id,
            'user_id' => $this->user->id,
        ]);
        $this->assertSame(0, $coupon->fresh()->used);
    }

    /** @test */
    public function area_matched_order_completes_and_mismatched_order_fails(): void
    {
        $coupon = $this->createCoupon('REVALAREAPAY');
        $this->target($coupon, 'dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id]]);

        $ok = $this->completeOrder($this->user, $coupon, $this->riyadh->id);
        $this->assertSame('completed', $ok->status);
        $this->assertSame(1, $coupon->fresh()->used);

        $coupon2 = $this->createCoupon('REVALAREANOP');
        $this->target($coupon2, 'dynamic', ['type' => 'area_in', 'value' => [$this->riyadh->id]]);

        try {
            $this->completeOrder($this->otherUser, $coupon2, $this->jeddah->id);
            $this->fail('Wrong-area completion must throw.');
        } catch (CouponConsumptionException $e) {
            $this->assertSame(CouponConsumptionException::REASON_NOT_ELIGIBLE, $e->reason);
        }

        $this->assertSame(0, $coupon2->fresh()->used);
    }

    /** @test */
    public function or_mode_without_any_assignments_enforces_tree(): void
    {
        $coupon = $this->createCoupon('REVALOR4');
        $this->target($coupon, 'assignment_or_dynamic', ['type' => 'min_completed_orders', 'value' => 99]);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 0]);

        // No assignment rows exist: the tree is the only authority.
        $failed = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertFalse($failed['valid']);
        $this->assertSame('not_eligible', $failed['reason']);

        CustomerMetrics::where('user_id', $this->user->id)->update(['completed_orders' => 100]);
        $this->assertTrue(CouponOrchestrator::validate($coupon, $this->user)['valid']);
    }

    /** @test */
    public function and_mode_with_zero_assignments_still_requires_assignment(): void
    {
        // No assignment rows exist at all: the assignment prong cannot be
        // satisfied, so even a passing tree must NOT validate (no downgrade
        // to pure-dynamic).
        $coupon = $this->createCoupon('REVALANDNOROWS');
        $this->target($coupon, 'assignment_and_dynamic', ['type' => 'min_completed_orders', 'value' => 0]);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 0]);

        $result = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertFalse($result['valid']);
        $this->assertSame('not_eligible', $result['reason']);
    }

    /** @test */
    public function empty_group_tree_fails_closed_at_apply(): void
    {
        $coupon = $this->createCoupon('REVALEMPTY');
        $this->target($coupon, 'dynamic', ['operator' => 'AND', 'rules' => []]);

        $result = CouponOrchestrator::validate($coupon, $this->user);
        $this->assertFalse($result['valid']);
        $this->assertSame('not_eligible', $result['reason']);
    }

    /** @test */
    public function payment_fails_closed_when_order_user_is_missing(): void
    {
        $coupon = $this->createCoupon('REVALNOUSER');
        $this->target($coupon, 'dynamic', ['type' => 'min_completed_orders', 'value' => 0]);
        CustomerMetrics::create(['user_id' => $this->user->id, 'completed_orders' => 0]);

        $order = Order::create([
            'user_id' => $this->user->id,
            'name' => 'Orphan Order',
            'user_phone' => '01000000000',
            'user_email' => 'test@test.com',
            'address' => '{}',
            'total_price' => 80.00,
            'price' => 90.00,
            'coupon' => $coupon->code,
            'coupon_discount' => 10,
            'status' => 'completed',
        ]);

        Transaction::create([
            'order_id' => $order->id,
            'user_id' => $this->user->id,
            'payment_method' => 'cod',
            'status' => 'paid',
            'paid_at' => now(),
            'amount' => 100,
            'invoice_id' => 'INV-REVAL-NOUSER',
        ]);

        // Account deleted after checkout: identity is gone, so eligibility
        // cannot be evaluated — must fail closed, never consume.
        $this->user->delete();
        $this->assertNull($order->fresh()->user);

        try {
            app(\App\Services\General\OrderService::class)->changeOrderStatus(null, 'completed', $order->id);
            $this->fail('Completion without a user must throw.');
        } catch (CouponConsumptionException $e) {
            $this->assertSame(CouponConsumptionException::REASON_NOT_ELIGIBLE, $e->reason);
        }

        $this->assertDatabaseMissing('coupon_usages', ['coupon_id' => $coupon->id]);
        $this->assertSame(0, $coupon->fresh()->used);
    }

    /** @test */
    public function legacy_coupon_without_targeting_keeps_existing_behavior(): void
    {
        $assigned = $this->createCoupon('REVALLEGACY');
        $this->assign($assigned, $this->user);

        $this->assertTrue(CouponOrchestrator::validate($assigned, $this->user)['valid']);

        $stranger = CouponOrchestrator::validate($assigned, $this->otherUser);
        $this->assertFalse($stranger['valid']);
        $this->assertSame('not_assigned', $stranger['reason']);
    }
}
