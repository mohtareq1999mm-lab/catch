<?php

namespace Tests\Unit\Services\Coupon\Eligibility;

use App\DTOs\Coupon\EligibilityResult;
use App\Enums\EligibilityRuleType;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use App\Services\Customer\CustomerMetricsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

class EligibilityEngineTest extends TestCase
{
    use RefreshDatabase;

    private EligibilityEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();
        $this->engine = app(EligibilityEngine::class);
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

    public function test_no_targeting_returns_eligible()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    public function test_assignment_mode_with_assignment_returns_eligible()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment',
        ]);

        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    public function test_assignment_mode_without_assignment_returns_ineligible()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment',
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertFalse($result->isEligible);
        $this->assertCount(1, $result->failedRules);
    }

    public function test_min_completed_orders_rule()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 5,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 3],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    public function test_min_completed_orders_rule_fails()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 2,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 5],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertFalse($result->isEligible);
        $this->assertCount(1, $result->failedRules);
    }

    public function test_min_total_spend_rule()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'total_qualifying_order_value' => 500.00,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_total_spend', 'value' => 250.00],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    public function test_not_claimed_rule_passes_when_no_claim()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'not_claimed'],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    public function test_not_claimed_rule_fails_when_claim_exists()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponClaim::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'claimed_at' => now(),
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'not_claimed'],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertFalse($result->isEligible);
    }

    public function test_and_operator_requires_all_rules_pass()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 3,
            'total_qualifying_order_value' => 100.00,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 2],
                    ['type' => 'min_total_spend', 'value' => 150.00], // Fails
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertFalse($result->isEligible);
    }

    public function test_or_operator_requires_one_rule_pass()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 3,
            'total_qualifying_order_value' => 100.00,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'OR',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 5], // Fails
                    ['type' => 'min_total_spend', 'value' => 50.00],  // Passes
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    public function test_unknown_rule_type_fails_closed()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'unknown_rule_type', 'value' => 123],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertFalse($result->isEligible);
    }

    public function test_max_completed_orders_rule()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 2,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'max_completed_orders', 'value' => 5],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
    }

    public function test_eligibility_snapshot_includes_metrics()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 10,
            'total_qualifying_order_value' => 1000.00,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 5],
                ],
            ],
        ]);

        $result = $this->engine->evaluate($coupon, $user);

        $this->assertTrue($result->isEligible);
        $this->assertArrayHasKey('completed_orders', $result->evaluatedMetrics);
        $this->assertEquals(10, $result->evaluatedMetrics['completed_orders']);
    }
}
