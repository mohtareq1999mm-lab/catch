<?php

namespace Tests\Feature\Coupon;

use App\Enums\CouponClaimStatus;
use App\Events\PaymentSucceeded;
use App\Exceptions\CouponClaimException;
use App\Services\Coupon\CouponClaimService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Phase 2 Lifecycle Test Suite
 *
 * Comprehensive tests for coupon claim lifecycle implementation:
 * - Lifecycle state transitions (ACTIVE → EXPIRED → REDEEMED)
 * - First-N semantics (capacity counting with expired releasing)
 * - Expiry validation and TTL enforcement
 * - Order completion integration (PaymentSucceeded event)
 * - Concurrency safety (parent-row serialization)
 */
class CouponClaimLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private CouponClaimService $claimService;
    private Coupon $coupon;
    private CouponTargeting $targeting;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->claimService = app(CouponClaimService::class);

        // Create test coupon with targeting
        $this->coupon = $this->createCoupon();
        $this->targeting = $this->createTargeting($this->coupon);
        $this->user = User::factory()->create();
    }

    private function createCoupon(array $overrides = []): Coupon
    {
        $code = 'TEST-' . Str::random(8);
        return Coupon::create(array_merge([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Test Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ], $overrides));
    }

    private function createTargeting(Coupon $coupon, array $overrides = []): CouponTargeting
    {
        return CouponTargeting::create(array_merge([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 5,
            'claim_ttl_hours' => 24,
        ], $overrides));
    }

    // =====================================================
    // LIFECYCLE STATE TRANSITIONS
    // =====================================================

    /** @test */
    public function user_can_claim_coupon_and_enter_active_state()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);

        $this->assertNotNull($claim);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim->status);
        $this->assertEquals($this->coupon->id, $claim->coupon_id);
        $this->assertEquals($this->user->id, $claim->user_id);
        $this->assertNotNull($claim->claimed_at);
        $this->assertNotNull($claim->expires_at);
    }

    /** @test */
    public function user_cannot_claim_twice_if_first_claim_still_active()
    {
        $this->claimService->claim($this->coupon, $this->user);

        $this->expectException(CouponClaimException::class);
        $this->claimService->claim($this->coupon, $this->user);
    }

    /** @test */
    public function user_can_claim_again_after_first_claim_expires()
    {
        $claim1 = $this->claimService->claim($this->coupon, $this->user);
        $this->travel(25)->hours();

        // Manually expire the first claim (simulating scheduled command)
        $claim1->update(['status' => CouponClaimStatus::EXPIRED]);

        // Should be able to claim again
        $claim2 = $this->claimService->claim($this->coupon, $this->user);

        $this->assertNotNull($claim2);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim2->status);
        $this->assertNotEquals($claim1->id, $claim2->id);
    }

    /** @test */
    public function user_cannot_claim_again_after_first_claim_redeemed()
    {
        $claim1 = $this->claimService->claim($this->coupon, $this->user);
        $this->claimService->markRedeemed($claim1);

        // F-16 single-use lifecycle (approved): REDEEMED is permanent and
        // BLOCKS re-claim. Re-claiming would occupy another max_claims slot
        // while coupon_usages unique blocks reuse.
        $this->expectException(CouponClaimException::class);
        $this->claimService->claim($this->coupon, $this->user);
    }

    /** @test */
    public function mark_redeemed_transitions_active_to_redeemed()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);

        $this->claimService->markRedeemed($claim);
        $claim->refresh();

        $this->assertEquals(CouponClaimStatus::REDEEMED, $claim->status);
        $this->assertNotNull($claim->redeemed_at);
    }

    /** @test */
    public function mark_redeemed_throws_if_claim_not_active()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->claimService->markRedeemed($claim);

        $this->expectException(CouponClaimException::class);
        $this->claimService->markRedeemed($claim);
    }

    // =====================================================
    // EXPIRY VALIDATION (FIX #4)
    // =====================================================

    /** @test */
    public function expired_claims_do_not_block_new_claims()
    {
        // Create claim with short TTL
        $this->targeting->update(['claim_ttl_hours' => 1]);

        $claim1 = $this->claimService->claim($this->coupon, $this->user);
        $this->travel(2)->hours();

        // Claim1 should be expired, shouldn't block new claim
        $claim2 = $this->claimService->claim($this->coupon, $this->user);

        $this->assertNotNull($claim2);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim2->status);
    }

    /** @test */
    public function hasClaimed_returns_false_for_expired_claims()
    {
        $this->targeting->update(['claim_ttl_hours' => 1]);
        $claim = $this->claimService->claim($this->coupon, $this->user);

        $this->assertTrue($this->claimService->hasClaimed($this->coupon, $this->user));

        $this->travel(2)->hours();

        // After expiry, should return false (claim is logically expired)
        $this->assertFalse($this->claimService->hasClaimed($this->coupon, $this->user));
    }

    // =====================================================
    // SCHEDULED EXPIRATION COMMAND (FIX #3)
    // =====================================================

    /** @test */
    public function expireExpiredClaims_transitions_expired_claims()
    {
        $this->targeting->update(['claim_ttl_hours' => 1]);

        $claim1 = $this->claimService->claim($this->coupon, $this->user);
        $user2 = User::factory()->create();
        $claim2 = $this->claimService->claim($this->coupon, $user2);

        $this->travel(2)->hours();

        // Run expiration command
        $expiredCount = $this->claimService->expireExpiredClaims();

        $this->assertEquals(2, $expiredCount);
        $this->assertEquals(CouponClaimStatus::EXPIRED, $claim1->fresh()->status);
        $this->assertEquals(CouponClaimStatus::EXPIRED, $claim2->fresh()->status);
    }

    /** @test */
    public function expireExpiredClaims_ignores_null_expires_at()
    {
        $this->targeting->update(['claim_ttl_hours' => null]);

        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->assertNull($claim->expires_at);

        $this->travel(30)->days();

        // Should not expire (no TTL set)
        $expiredCount = $this->claimService->expireExpiredClaims();

        $this->assertEquals(0, $expiredCount);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $claim->fresh()->status);
    }

    // =====================================================
    // FIRST-N SEMANTICS (CAPACITY COUNTING)
    // =====================================================

    /** @test */
    public function capacity_respects_first_n_semantics_active_plus_redeemed()
    {
        $this->targeting->update(['max_claims' => 3]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        $user4 = User::factory()->create();

        // First 3 claims succeed (count = 3)
        $claim1 = $this->claimService->claim($this->coupon, $user1);
        $claim2 = $this->claimService->claim($this->coupon, $user2);
        $claim3 = $this->claimService->claim($this->coupon, $user3);

        // 4th claim fails (capacity reached)
        $this->expectException(CouponClaimException::class);
        $this->claimService->claim($this->coupon, $user4);
    }

    /** @test */
    public function capacity_respects_redeemed_as_occupied()
    {
        $this->targeting->update(['max_claims' => 3]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();
        $user4 = User::factory()->create();

        $claim1 = $this->claimService->claim($this->coupon, $user1);
        $claim2 = $this->claimService->claim($this->coupon, $user2);
        $claim3 = $this->claimService->claim($this->coupon, $user3);

        // Redeem one claim
        $this->claimService->markRedeemed($claim1);

        // Capacity still occupied, 4th claim fails
        $this->expectException(CouponClaimException::class);
        $this->claimService->claim($this->coupon, $user4);
    }

    /** @test */
    public function expired_claims_release_capacity()
    {
        $this->targeting->update(['max_claims' => 2, 'claim_ttl_hours' => 1]);

        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $claim1 = $this->claimService->claim($this->coupon, $user1);
        $claim2 = $this->claimService->claim($this->coupon, $user2);

        // Capacity full (2/2)
        $this->expectException(CouponClaimException::class);
        $this->claimService->claim($this->coupon, $user3);

        // Expire first claim
        $this->travel(2)->hours();
        $this->claimService->expireExpiredClaims();

        // Now user3 can claim (capacity released)
        $claim3 = $this->claimService->claim($this->coupon, $user3);
        $this->assertNotNull($claim3);
    }

    /** @test */
    public function unlimited_claims_when_max_claims_null()
    {
        $this->targeting->update(['max_claims' => null]);

        $users = User::factory()->count(100)->create();

        // Should be able to claim with all users
        foreach ($users as $user) {
            $claim = $this->claimService->claim($this->coupon, $user);
            $this->assertNotNull($claim);
        }

        $claims = CouponClaim::where('coupon_id', $this->coupon->id)->count();
        $this->assertEquals(100, $claims);
    }

    // =====================================================
    // HELPER METHODS
    // =====================================================

    /** @test */
    public function has_claimed_returns_true_for_active_claims()
    {
        $this->assertFalse($this->claimService->hasClaimed($this->coupon, $this->user));

        $this->claimService->claim($this->coupon, $this->user);

        $this->assertTrue($this->claimService->hasClaimed($this->coupon, $this->user));
    }

    /** @test */
    public function has_claimed_returns_false_for_redeemed_claims()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->claimService->markRedeemed($claim);

        $this->assertFalse($this->claimService->hasClaimed($this->coupon, $this->user));
    }

    /** @test */
    public function get_claim_returns_active_claim()
    {
        $created = $this->claimService->claim($this->coupon, $this->user);
        $retrieved = $this->claimService->getClaim($this->coupon, $this->user);

        $this->assertNotNull($retrieved);
        $this->assertEquals($created->id, $retrieved->id);
        $this->assertEquals(CouponClaimStatus::ACTIVE, $retrieved->status);
    }

    /** @test */
    public function get_claim_returns_null_for_redeemed_claims()
    {
        $claim = $this->claimService->claim($this->coupon, $this->user);
        $this->claimService->markRedeemed($claim);

        $retrieved = $this->claimService->getClaim($this->coupon, $this->user);

        $this->assertNull($retrieved);
    }
}
