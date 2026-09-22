<?php

namespace Tests\Feature\Coupon;

use App\Enums\CouponClaimStatus;
use App\Enums\EligibilityRuleType;
use App\Events\PaymentSucceeded;
use App\Exceptions\CouponClaimException;
use App\Services\Coupon\CouponClaimService;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 2 Eligibility Lifecycle Integration Tests
 *
 * Comprehensive tests proving EligibilityEngine → CouponClaimService → Claim Lifecycle
 * work consistently together. Tests exercise the REAL eligibility evaluation path,
 * not mocked or bypassed.
 *
 * Key Semantics:
 * - NOT_CLAIMED: User has NO current usable ACTIVE claim (expired/redeemed claims DO NOT block)
 * - CLAIMED: User HAS ANY historical claim (inverse of NOT_CLAIMED)
 * - Expiry: expires_at IS NULL = unlimited, expires_at > now() = usable
 */
class CouponEligibilityLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private CouponClaimService $claimService;
    private EligibilityEngine $eligibilityEngine;
    private Coupon $coupon;
    private CouponTargeting $targeting;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claimService = app(CouponClaimService::class);
        $this->eligibilityEngine = app(EligibilityEngine::class);

        $this->coupon = $this->createCouponWithNotClaimedRule();
        $this->targeting = $this->coupon->targeting;
        $this->user = User::factory()->create();
    }

    private function createCouponWithNotClaimedRule(): Coupon
    {
        $code = 'TEST-' . Str::random(8);
        $coupon = Coupon::create([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Test Coupon NOT_CLAIMED',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 100,
            'claim_ttl_hours' => 24,
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    [
                        'type' => EligibilityRuleType::NOT_CLAIMED->value,
                        'value' => null,
                    ],
                ],
            ],
        ]);

        return $coupon;
    }

    private function createCouponWithClaimedRule(): Coupon
    {
        $code = 'CLAIMED-' . Str::random(8);
        $coupon = Coupon::create([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Test Coupon CLAIMED',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 100,
            'claim_ttl_hours' => 24,
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    [
                        'type' => EligibilityRuleType::CLAIMED->value,
                        'value' => null,
                    ],
                ],
            ],
        ]);

        return $coupon;
    }

    // =====================================================
    // NOT_CLAIMED RULE INTEGRATION TESTS (9 scenarios)
    // =====================================================

    /** @test */
    public function not_claimed_rule_passes_when_no_claim_exists()
    {
        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);

        $this->assertTrue($result->isEligible);
        $this->assertContains(EligibilityRuleType::NOT_CLAIMED->value, array_column($result->passedRules, 'type'));
    }

    /** @test */
    public function not_claimed_rule_fails_when_active_unexpired_claim_exists()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim->status);

        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);

        $this->assertFalse($result->isEligible);
        $this->assertContains(EligibilityRuleType::NOT_CLAIMED->value, array_column($result->failedRules, 'type'));
    }

    /** @test */
    public function not_claimed_rule_passes_when_active_claim_is_expired()
    {
        $this->targeting->update(['claim_ttl_hours' => 1]);

        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim->status);
        $this->assertNotNull($claim->expires_at);

        // Travel past expiry
        $this->travel(2)->hours();

        // Eligibility check should pass (expired claim doesn't block)
        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);

        $this->assertTrue($result->isEligible);
        $this->assertContains(EligibilityRuleType::NOT_CLAIMED->value, array_column($result->passedRules, 'type'));
    }

    /** @test */
    public function not_claimed_rule_passes_when_claim_is_expired_status()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);
        $claim->update(['status' => CouponClaimStatus::EXPIRED]);

        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);

        $this->assertTrue($result->isEligible);
        $this->assertContains(EligibilityRuleType::NOT_CLAIMED->value, array_column($result->passedRules, 'type'));
    }

    /** @test */
    public function not_claimed_rule_fails_when_claim_is_redeemed()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->claimService->markRedeemed($claim);

        // F-16 single-use lifecycle (approved): REDEEMED is permanent and
        // blocks NOT_CLAIMED — the consumed coupon cannot be re-earned.
        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);

        $this->assertFalse($result->isEligible);
        $this->assertContains(EligibilityRuleType::NOT_CLAIMED->value, array_column($result->failedRules, 'type'));
    }

    /** @test */
    public function not_claimed_rule_fails_when_claim_has_unlimited_ttl_and_is_active()
    {
        $this->targeting->update(['claim_ttl_hours' => null]);

        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim->status);
        $this->assertNull($claim->expires_at);

        $this->travel(30)->days();

        // Should still fail - unlimited TTL means never expires
        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);

        $this->assertFalse($result->isEligible);
        $this->assertContains(EligibilityRuleType::NOT_CLAIMED->value, array_column($result->failedRules, 'type'));
    }

    /** @test */
    public function not_claimed_rule_handles_multiple_claims_correctly()
    {
        // Create first claim
        $claim1 = $this->claimService->claim($this->coupon, $this->user);
        $this->claimService->markRedeemed($claim1);

        // After redeem, NOT_CLAIMED fails (F-16: REDEEMED blocks re-claim).
        $result1 = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertFalse($result1->isEligible);

        // Second claim is rejected.
        $this->expectException(CouponClaimException::class);
        $this->claimService->claim($this->coupon, $this->user);
    }

    /** @test */
    public function not_claimed_rule_expiry_check_is_consistent_with_claim_service()
    {
        $this->targeting->update(['claim_ttl_hours' => 2]);

        $claim = $this->claimService->claim($this->coupon, $this->user);

        // Time boundary: just before expiry (1h 59min)
        $this->travel(1)->hours();
        $this->travel(59)->minutes();

        // Should still have active claim
        $this->assertTrue($this->claimService->hasClaimed($this->coupon, $this->user));
        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertFalse($result->isEligible);

        // Time boundary: just after expiry (total 2h 1min)
        $this->travel(2)->minutes();

        // Should NOT have active claim
        $this->assertFalse($this->claimService->hasClaimed($this->coupon, $this->user));
        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertTrue($result->isEligible);
    }

    /** @test */
    public function not_claimed_rule_passes_after_scheduler_expires_claim()
    {
        $this->targeting->update(['claim_ttl_hours' => 1]);

        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->assertFalse($this->eligibilityEngine->evaluate($this->coupon, $this->user)->isEligible);

        // Travel past expiry and run scheduler
        $this->travel(2)->hours();
        $expiredCount = $this->claimService->expireExpiredClaims();

        $this->assertEquals(1, $expiredCount);
        $claim->refresh();
        $this->assertEquals(CouponClaimStatus::EXPIRED, $claim->status);

        // NOW eligibility should pass
        $result = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertTrue($result->isEligible);
        $this->assertContains(EligibilityRuleType::NOT_CLAIMED->value, array_column($result->passedRules, 'type'));
    }

    // =====================================================
    // CLAIMED RULE INTEGRATION TESTS (4 scenarios)
    // =====================================================

    /** @test */
    public function claimed_rule_fails_when_no_claim_exists()
    {
        $claimedCoupon = $this->createCouponWithClaimedRule();

        $result = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);

        $this->assertFalse($result->isEligible);
        $this->assertContains(EligibilityRuleType::CLAIMED->value, array_column($result->failedRules, 'type'));
    }

    /** @test */
    public function claimed_rule_passes_when_active_claim_exists()
    {
        $claimedCoupon = $this->createCouponWithClaimedRule();

        // Create claim directly (bypass eligibility checks which would fail initially)
        CouponClaim::create([
            'coupon_id' => $claimedCoupon->id,
            'user_id' => $this->user->id,
            'status' => CouponClaimStatus::ACTIVE,
            'claimed_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);

        $result = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);

        $this->assertTrue($result->isEligible);
        $this->assertContains(EligibilityRuleType::CLAIMED->value, array_column($result->passedRules, 'type'));
    }

    /** @test */
    public function claimed_rule_passes_when_claim_is_expired()
    {
        $claimedCoupon = $this->createCouponWithClaimedRule();

        // Create expired claim directly
        CouponClaim::create([
            'coupon_id' => $claimedCoupon->id,
            'user_id' => $this->user->id,
            'status' => CouponClaimStatus::EXPIRED,
            'claimed_at' => now()->subHours(2),
            'expires_at' => now()->subHours(1),
        ]);

        // CLAIMED checks ANY historical claim, so expired still counts
        $result = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);

        $this->assertTrue($result->isEligible);
        $this->assertContains(EligibilityRuleType::CLAIMED->value, array_column($result->passedRules, 'type'));
    }

    /** @test */
    public function claimed_rule_passes_when_claim_is_redeemed()
    {
        $claimedCoupon = $this->createCouponWithClaimedRule();

        // Create redeemed claim directly
        CouponClaim::create([
            'coupon_id' => $claimedCoupon->id,
            'user_id' => $this->user->id,
            'status' => CouponClaimStatus::REDEEMED,
            'claimed_at' => now()->subHours(1),
            'expires_at' => now()->addHours(23),
            'redeemed_at' => now(),
        ]);

        // CLAIMED checks ANY historical claim, so redeemed counts
        $result = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);

        $this->assertTrue($result->isEligible);
        $this->assertContains(EligibilityRuleType::CLAIMED->value, array_column($result->passedRules, 'type'));
    }

    // =====================================================
    // END-TO-END FLOW TESTS (through real eligibility path)
    // =====================================================

    /** @test */
    public function end_to_end_claim_redeem_reclaim_through_eligibility()
    {
        // Step 1: User claims (NOT_CLAIMED passes, claim allowed)
        $result1 = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertTrue($result1->isEligible, 'Should be eligible to claim initially');

        $claim1 = $this->claimService->claim($this->coupon, $this->user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim1->status);

        // Step 2: User cannot claim again (NOT_CLAIMED fails)
        $result2 = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertFalse($result2->isEligible, 'Should NOT be eligible while claim is active');

        // Step 3: Order completes, claim redeemed
        $this->claimService->markRedeemed($claim1);
        $claim1->refresh();
        $this->assertEquals(CouponClaimStatus::REDEEMED, $claim1->status);

        // Step 4: User CANNOT claim again (F-16: REDEEMED is permanent).
        $result3 = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertFalse($result3->isEligible, 'Must NOT be eligible to reclaim after redemption');

        $this->expectException(CouponClaimException::class);
        $this->claimService->claim($this->coupon, $this->user);
    }

    /** @test */
    public function end_to_end_claim_expire_reclaim_through_eligibility()
    {
        $this->targeting->update(['claim_ttl_hours' => 1]);

        // Step 1: User claims
        $result1 = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertTrue($result1->isEligible);

        $claim1 = $this->claimService->claim($this->coupon, $this->user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim1->status);

        // Step 2: Claim blocks re-claim
        $result2 = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertFalse($result2->isEligible);

        // Step 3: Time passes, claim expires
        $this->travel(2)->hours();

        // Step 4: User CAN claim again (expired claim doesn't block)
        $result3 = $this->eligibilityEngine->evaluate($this->coupon, $this->user);
        $this->assertTrue($result3->isEligible, 'Should be eligible after claim expires');

        $claim2 = $this->claimService->claim($this->coupon, $this->user);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim2->status);
        $this->assertNotEquals($claim1->id, $claim2->id);

        // Step 5: Verify scheduler would transition first claim
        $expiredCount = $this->claimService->expireExpiredClaims();
        $this->assertGreaterThanOrEqual(1, $expiredCount);
        $claim1->refresh();
        $this->assertEquals(CouponClaimStatus::EXPIRED, $claim1->status);
    }

    /** @test */
    public function end_to_end_claimed_rule_with_lifecycle_states()
    {
        $claimedCoupon = $this->createCouponWithClaimedRule();

        // Step 1: No claim, CLAIMED fails
        $result1 = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);
        $this->assertFalse($result1->isEligible);

        // Step 2: Create claim directly, CLAIMED passes
        $claim1 = CouponClaim::create([
            'coupon_id' => $claimedCoupon->id,
            'user_id' => $this->user->id,
            'status' => CouponClaimStatus::ACTIVE,
            'claimed_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $result2 = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);
        $this->assertTrue($result2->isEligible);

        // Step 3: Redeem claim, CLAIMED still passes (historical claim counts)
        $claim1->update(['status' => CouponClaimStatus::REDEEMED, 'redeemed_at' => now()]);
        $result3 = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);
        $this->assertTrue($result3->isEligible);

        // Step 4: Create another claim, CLAIMED still passes
        $claim2 = CouponClaim::create([
            'coupon_id' => $claimedCoupon->id,
            'user_id' => $this->user->id,
            'status' => CouponClaimStatus::ACTIVE,
            'claimed_at' => now(),
            'expires_at' => now()->addHours(24),
        ]);
        $result4 = $this->eligibilityEngine->evaluate($claimedCoupon, $this->user);
        $this->assertTrue($result4->isEligible);
    }
}
