<?php

namespace Tests\Feature\Coupon;

use App\Enums\EligibilityRuleType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CouponClaimTest extends TestCase
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

    public function test_guest_cannot_claim_coupon()
    {
        $coupon = $this->createCoupon();

        $response = $this->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(401);
    }

    public function test_authenticated_user_can_claim_eligible_coupon()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 5,
            'total_qualifying_order_value' => 500.00,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 3],
                ],
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'coupon_id',
                    'user_id',
                    'claimed_at',
                    'eligibility_snapshot',
                    'created_at',
                ],
            ])
            ->assertJson([
                'success' => true,
                'data' => [
                    'coupon_id' => $coupon->id,
                    'user_id' => $user->id,
                ],
            ]);

        $this->assertDatabaseHas('coupon_claims', [
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_already_claimed_returns_409()
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

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => [
                    'reason' => 'already_claimed',
                ],
            ]);
    }

    public function test_not_eligible_returns_409()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 1,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 5],
                ],
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => [
                    'reason' => 'not_eligible',
                ],
            ]);
    }

    public function test_claim_not_required_returns_409()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => false,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => [
                    'reason' => 'claim_not_required',
                ],
            ]);
    }

    public function test_no_targeting_returns_409()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => [
                    'reason' => 'no_targeting',
                ],
            ]);
    }

    public function test_max_claims_total_capacity_enforced()
    {
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create(['user_id' => $user1->id]);
        CustomerMetrics::create(['user_id' => $user2->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 1, // Total capacity: only 1 user can claim
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        // First user claims successfully
        $response1 = $this->actingAs($user1, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response1->assertStatus(201);

        // Second user should be rejected (total capacity reached)
        $response2 = $this->actingAs($user2, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response2->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => [
                    'reason' => 'max_claims_reached',
                ],
            ]);

        // Verify exactly 1 claim exists
        $this->assertEquals(1, CouponClaim::where('coupon_id', $coupon->id)->count());
    }

    public function test_coupon_not_found_returns_404()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/99999/claim");

        $response->assertStatus(404);
    }

    public function test_eligibility_snapshot_captured_at_claim_time()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create([
            'user_id' => $user->id,
            'completed_orders' => 10,
            'total_qualifying_order_value' => 1000.00,
        ]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => [
                'operator' => 'AND',
                'rules' => [
                    ['type' => 'min_completed_orders', 'value' => 5],
                ],
            ],
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(201);

        $claim = CouponClaim::where('coupon_id', $coupon->id)
            ->where('user_id', $user->id)
            ->first();

        $this->assertNotNull($claim->eligibility_snapshot);
        $this->assertArrayHasKey('evaluated_metrics', $claim->eligibility_snapshot);
        $this->assertEquals(10, $claim->eligibility_snapshot['evaluated_metrics']['completed_orders']);
    }

    public function test_assignment_mode_requires_assignment()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment',
            'require_claim' => true,
        ]);

        // No assignment exists
        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(409)
            ->assertJson(['success' => false]);
    }

    public function test_assignment_mode_with_assignment_succeeds()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment',
            'require_claim' => true,
        ]);

        CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response->assertStatus(201);
    }

    public function test_user_cannot_claim_same_coupon_twice()
    {
        $user = User::factory()->create();
        $coupon = $this->createCoupon();

        CustomerMetrics::create(['user_id' => $user->id]);

        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'max_claims' => 10, // High limit to focus on duplicate prevention
            'rule_tree' => ['operator' => 'AND', 'rules' => []],
        ]);

        // First claim succeeds
        $response1 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response1->assertStatus(201);

        // Second claim by same user should fail with already_claimed
        $response2 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/general/coupons/{$coupon->id}/claim");

        $response2->assertStatus(409)
            ->assertJson([
                'success' => false,
                'data' => [
                    'reason' => 'already_claimed',
                ],
            ]);

        // Verify exactly 1 claim exists for this user
        $this->assertEquals(1, CouponClaim::where('user_id', $user->id)->count());
    }
}
