<?php

namespace Tests\Feature\CouponDistribution;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

class AvailableCouponsApiTest extends TestCase
{
    use RefreshDatabase;

    private function createCoupon(array $ruleTree, bool $requireClaim = true): Coupon
    {
        $code = 'AVL-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Available Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic',
            'require_claim' => $requireClaim, 'rule_tree' => $ruleTree,
        ]);

        return $coupon;
    }

    public function test_unauthenticated_is_rejected()
    {
        $this->getJson('/api/v1/general/coupons/available')->assertUnauthorized();
    }

    public function test_eligible_coupon_appears_with_owner_safe_shell()
    {
        $coupon = $this->createCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/general/coupons/available');
        $response->assertOk()->assertJsonPath('success', true);

        $items = $response->json('data.data');
        $this->assertCount(1, $items);

        $item = $items[0];
        $this->assertSame($coupon->id, $item['id']);
        $this->assertSame('claimable', $item['claim_status']);
        $this->assertTrue($item['requires_claim']);
        $this->assertSame('claim', $item['action']);
        $this->assertArrayNotHasKey('code', $item);
        $this->assertArrayNotHasKey('coupon_code', $item);
        $this->assertArrayNotHasKey('rule_tree', $item);
        $this->assertStringNotContainsString($coupon->code, $response->getContent());
    }

    public function test_ineligible_coupon_is_excluded()
    {
        $this->createCoupon(['type' => 'min_completed_orders', 'value' => 500]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/general/coupons/available');
        $response->assertOk();
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_claimed_coupon_reports_apply_action()
    {
        $coupon = $this->createCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);
        $claim = CouponClaim::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'claimed_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/general/coupons/available');
        $response->assertOk();

        $item = $response->json('data.data.0');
        $this->assertSame('claimed', $item['claim_status']);
        $this->assertSame('apply', $item['action']);
        $this->assertSame($claim->id, $item['claim_id']);
        $this->assertArrayNotHasKey('code', $item);
    }

    public function test_pagination_meta_is_present()
    {
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);
        $this->createCoupon(['type' => 'min_completed_orders', 'value' => 0]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/general/coupons/available?limit=1');
        $response->assertOk();
        $this->assertSame(1, $response->json('data.meta.per_page'));
        $this->assertArrayHasKey('current_page', $response->json('data.meta'));
        $this->assertArrayHasKey('has_more_pages', $response->json('data.meta'));
    }

    public function test_meta_total_counts_only_eligible_items()
    {
        // Two valid targeted coupons, one eligible: meta.total must not
        // leak the hidden campaign count.
        $this->createCoupon(['type' => 'min_completed_orders', 'value' => 0]);
        $this->createCoupon(['type' => 'min_completed_orders', 'value' => 500]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/general/coupons/available');
        $response->assertOk();
        $this->assertCount(1, $response->json('data.data'));
        $this->assertSame(1, $response->json('data.meta.total'));
    }
}
