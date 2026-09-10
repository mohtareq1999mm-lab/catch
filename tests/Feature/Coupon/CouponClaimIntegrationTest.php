<?php

namespace Tests\Feature\Coupon;

use App\Services\Coupon\CouponOrchestrator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Integration tests verifying that claim requirement is enforced
 * in the actual coupon validation flow (CouponOrchestrator).
 */
class CouponClaimIntegrationTest extends TestCase
{
    use RefreshDatabase;

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

    public function test_coupon_without_targeting_can_be_validated_directly()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        $result = CouponOrchestrator::validate($coupon, $user);

        $this->assertTrue($result['valid']);
    }

    public function test_coupon_with_require_claim_false_can_be_validated_without_claim()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => false,
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        $result = CouponOrchestrator::validate($coupon, $user);

        $this->assertTrue($result['valid']);
    }

    public function test_coupon_with_require_claim_true_fails_without_claim()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        $result = CouponOrchestrator::validate($coupon, $user);

        $this->assertFalse($result['valid']);
        $this->assertEquals('claim_required', $result['reason']);
        $this->assertEquals(__('coupon.claim_required'), $result['message']);
    }

    public function test_coupon_with_require_claim_true_succeeds_with_claim()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        CouponClaim::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $result = CouponOrchestrator::validate($coupon, $user);

        $this->assertTrue($result['valid']);
    }

    public function test_validateByCode_enforces_claim_requirement()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon(['code' => 'TESTCODE123']);

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        // Without claim
        $result = CouponOrchestrator::validateByCode('TESTCODE123', $user);
        $this->assertFalse($result['valid']);
        $this->assertEquals('claim_required', $result['reason']);

        // With claim
        CouponClaim::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $result = CouponOrchestrator::validateByCode('TESTCODE123', $user);
        $this->assertTrue($result['valid']);
    }

    public function test_guest_user_not_affected_by_claim_requirement()
    {
        $coupon = $this->createCoupon();

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        // Guest users (null user) bypass claim check
        $result = CouponOrchestrator::validate($coupon, null);

        // Will pass claim check but may fail other validation
        $this->assertTrue($result['valid']);
    }

    public function test_claim_check_happens_before_static_validation()
    {
        $user = User::factory()->create();
        
        // Create an EXPIRED coupon with require_claim
        $coupon = $this->createCoupon([
            'start_date' => now()->subMonth(),
            'end_date' => now()->subDay(), // Already expired
        ]);

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        // Without claim: should fail with claim_required (not expired)
        $result = CouponOrchestrator::validate($coupon, $user);
        $this->assertFalse($result['valid']);
        $this->assertEquals('claim_required', $result['reason']);

        // With claim: should now fail with expired
        CouponClaim::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        $result = CouponOrchestrator::validate($coupon, $user);
        $this->assertFalse($result['valid']);
        $this->assertEquals('expired', $result['reason']);
    }

    public function test_claimed_coupon_still_respects_static_validation()
    {
        $user = User::factory()->create();
        
        // Create an INACTIVE coupon
        $coupon = $this->createCoupon(['status' => false]);

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        CouponClaim::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        // Even with valid claim, inactive coupon should fail
        $result = CouponOrchestrator::validate($coupon, $user);
        
        $this->assertFalse($result['valid']);
        $this->assertEquals('disabled', $result['reason']);
    }
}
