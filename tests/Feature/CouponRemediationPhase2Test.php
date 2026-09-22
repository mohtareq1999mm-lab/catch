<?php

namespace Tests\Feature;

use App\Enums\CouponClaimStatus;
use App\Services\Coupon\CouponClaimService;
use App\Services\Coupon\RuleTreeValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 2 remediation proofs: nested rule engine, combined modes,
 * F-16 claim lifecycle, targeting REST, assignment idempotency, F-07 lookup.
 */
class CouponRemediationPhase2Test extends TestCase
{
    use RefreshDatabase;

    private const PREFIX = '/api/v1';

    private function makeAdmin(): User
    {
        return User::factory()->create(['type' => 'admin']);
    }

    private function grant(User $user, array $permissions): void
    {
        foreach ($permissions as $name) {
            try {
                \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
            } catch (\Throwable $e) {
            }
        }
        try {
            $user->givePermissionTo($permissions);
        } catch (\Throwable $e) {
        }
    }

    private function createCoupon(string $code, array $overrides = []): Coupon
    {
        $coupon = Coupon::create(array_merge([
            'name' => 'Phase2 Coupon',
            'slug' => 'phase2-'.Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));
        $coupon->update(['code' => $code]);

        return $coupon->fresh();
    }

    /** @test */
    public function nested_and_inside_or_evaluates_correctly(): void
    {
        $coupon = $this->createCoupon('NEST1');
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 5, 'total_qualifying_order_value' => 100]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'OR',
                'rules' => [
                    ['operator' => 'AND', 'rules' => [
                        ['type' => 'min_completed_orders', 'value' => 3],
                        ['type' => 'min_total_spend', 'value' => 1000], // fails
                    ]],
                    ['type' => 'has_assignment'], // fails (no assignment)
                ],
            ],
        ]);

        $result = app(\App\Services\Coupon\Eligibility\EligibilityEngine::class)->evaluate($coupon, $user);
        $this->assertFalse($result->isEligible, 'Nested AND fails + second branch fails => ineligible');

        // Now give assignment => OR passes via second branch
        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);
        $result2 = app(\App\Services\Coupon\Eligibility\EligibilityEngine::class)->evaluate($coupon, $user);
        $this->assertTrue($result2->isEligible, 'OR second branch has_assignment passes');
    }

    /** @test */
    public function empty_group_and_unknown_operator_fail_closed(): void
    {
        $coupon = $this->createCoupon('NEST2');
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id]);
        $engine = app(\App\Services\Coupon\Eligibility\EligibilityEngine::class);

        CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => 'dynamic', 'rule_tree' => ['operator' => 'AND', 'rules' => []]]);
        $this->assertFalse($engine->evaluate($coupon, $user)->isEligible, 'Empty group fails closed');

        $coupon->targeting->update(['rule_tree' => ['operator' => 'XOR', 'rules' => [['type' => 'min_completed_orders', 'value' => 0]]]]);
        $this->assertFalse($engine->evaluate($coupon->fresh(), $user)->isEligible, 'Unknown operator fails closed');

        $coupon->targeting->update(['rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'bogus_rule', 'value' => 1]]]]);
        $this->assertFalse($engine->evaluate($coupon->fresh(), $user)->isEligible, 'Unknown rule fails closed');
    }

    /** @test */
    public function rule_tree_validator_rejects_malformed(): void
    {
        $this->assertTrue(RuleTreeValidator::validate(null)['valid']);
        $this->assertTrue(RuleTreeValidator::validate(['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 2]]])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['operator' => 'AND', 'rules' => []])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['operator' => 'XOR', 'rules' => [['type' => 'min_completed_orders', 'value' => 1]]])['valid']);
        $this->assertFalse(RuleTreeValidator::validate(['operator' => 'AND', 'rules' => [['type' => 'nope', 'value' => 1]]])['valid']);

        // Deep nesting beyond 10 fails
        $deep = ['type' => 'min_completed_orders', 'value' => 0];
        for ($i = 0; $i < 12; $i++) {
            $deep = ['operator' => 'AND', 'rules' => [$deep]];
        }
        $this->assertFalse(RuleTreeValidator::validate($deep)['valid']);
    }

    /** @test */
    public function combined_modes_enforce_assignment_and_dynamic(): void
    {
        $coupon = $this->createCoupon('COMB1');
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 5]);
        $engine = app(\App\Services\Coupon\Eligibility\EligibilityEngine::class);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment_and_dynamic',
            'rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 3]]],
        ]);

        // Dynamic passes but no assignment => ineligible
        $this->assertFalse($engine->evaluate($coupon, $user)->isEligible);

        CouponAssignment::create(['coupon_id' => $coupon->id, 'user_id' => $user->id]);
        $this->assertTrue($engine->evaluate($coupon->fresh(), $user)->isEligible);

        $coupon->targeting->update(['mode' => 'assignment_or_dynamic']);
        $other = User::factory()->create();
        CustomerMetrics::create(['user_id' => $other->id, 'completed_orders' => 10]);
        // No assignment but dynamic passes => eligible via OR
        $this->assertTrue($engine->evaluate($coupon->fresh(), $other)->isEligible);
    }

    /** @test */
    public function redeemed_claim_blocks_reclaim_but_expired_allows(): void
    {
        $coupon = $this->createCoupon('F16A');
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic',
            'require_claim' => true, 'max_claims' => 10, 'claim_ttl_hours' => 24,
            'rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 0]]],
        ]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $svc = app(CouponClaimService::class);
        $claim = $svc->claim($coupon, $user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim->status);

        // Redeem then re-claim must be blocked (F-16)
        $svc->markRedeemed($claim);
        try {
            $svc->claim($coupon, $user);
            $this->fail('REDEEMED re-claim must throw');
        } catch (\App\Exceptions\CouponClaimException $e) {
            $this->assertEquals(\App\Exceptions\CouponClaimException::REASON_ALREADY_CLAIMED, $e->reason);
        }

        // Expired claim allows re-claim
        $coupon2 = $this->createCoupon('F16B');
        CouponTargeting::create([
            'coupon_id' => $coupon2->id, 'mode' => 'dynamic',
            'require_claim' => true, 'max_claims' => 10, 'claim_ttl_hours' => 24,
        ]);
        $claim2 = $svc->claim($coupon2, $user);
        $claim2->update(['status' => CouponClaimStatus::EXPIRED]);
        $reclaim = $svc->claim($coupon2, $user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $reclaim->status);
    }

    /** @test */
    public function admin_targeting_crud_is_reachable_and_validated(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);
        $this->grant($admin, ['view-coupons', 'create-coupon', 'update-coupon']);
        $coupon = $this->createCoupon('TARG1');

        // View-only admin cannot write (BLOCKER fix)
        $viewer = User::factory()->create(['type' => 'admin']);
        $this->grant($viewer, ['view-coupons']);
        Sanctum::actingAs($viewer);
        $forbidden = $this->putJson(self::PREFIX."/admin/coupons/{$coupon->id}/targeting", [
            'mode' => 'dynamic', 'require_claim' => false,
        ]);
        $forbidden->assertStatus(403);

        Sanctum::actingAs($admin);

        // Create
        $res = $this->putJson(self::PREFIX."/admin/coupons/{$coupon->id}/targeting", [
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 100,
            'claim_ttl_hours' => 24,
            'rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 3]]],
        ]);
        $res->assertOk();
        $res->assertJsonPath('success', true);

        // Show
        $show = $this->getJson(self::PREFIX."/admin/coupons/{$coupon->id}/targeting");
        $show->assertOk();
        $show->assertJsonPath('data.mode', 'dynamic');

        // Invalid rule_tree rejected 422
        $bad = $this->putJson(self::PREFIX."/admin/coupons/{$coupon->id}/targeting", [
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'XOR', 'rules' => [['type' => 'bogus', 'value' => 1]]],
        ]);
        $bad->assertStatus(422);

        // Delete
        $del = $this->deleteJson(self::PREFIX."/admin/coupons/{$coupon->id}/targeting");
        $del->assertOk();
        $this->assertDatabaseMissing('coupon_targetings', ['coupon_id' => $coupon->id]);
    }

    /** @test */
    public function duplicate_assignment_returns_409_not_500(): void
    {
        $admin = $this->makeAdmin();
        Sanctum::actingAs($admin);
        $this->grant($admin, ['view-coupon-assignments', 'create-coupon-assignment']);
        $coupon = $this->createCoupon('ASS409');
        $user = User::factory()->create();

        $first = $this->postJson("/api/v1/coupons/{$coupon->id}/assignments", [
            'user_id' => $user->id, 'max_uses' => 1,
        ]);
        $first->assertStatus(201);

        $second = $this->postJson("/api/v1/coupons/{$coupon->id}/assignments", [
            'user_id' => $user->id, 'max_uses' => 1,
        ]);
        $second->assertStatus(409);
        $second->assertJsonPath('success', false);
    }

    /** @test */
    public function canonical_lookup_is_case_insensitive_and_indexed(): void
    {
        $coupon = $this->createCoupon('SAVE10');
        $this->assertEquals('SAVE10', $coupon->code);

        $found = Coupon::byCode('  save10 ')->first();
        $this->assertNotNull($found);
        $this->assertEquals($coupon->id, $found->id);

        $this->assertNull(Coupon::byCode('')->first());
        $this->assertNull(Coupon::byCode(null)->first());
    }

    /** @test */
    public function claim_response_does_not_leak_failed_rules(): void
    {
        $coupon = $this->createCoupon('LEAK1');
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'AND', 'rules' => [['type' => 'min_completed_orders', 'value' => 9999]]],
        ]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 0]);
        Sanctum::actingAs($user);

        $res = $this->postJson(self::PREFIX."/general/coupons/{$coupon->id}/claim");
        $res->assertStatus(409);
        $payload = $res->json('data');
        $this->assertArrayNotHasKey('failed_rules', (array) $payload);
        $this->assertArrayNotHasKey('context', (array) $payload);
        $this->assertEquals('not_eligible', $payload['reason'] ?? null);
    }
}
