<?php

namespace Tests\Feature\CouponDistribution;

use App\Services\Coupon\Distribution\Selection\CouponCandidateSelector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Marvel\Database\Models\Address;
use Marvel\Database\Models\Country;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\Governorate;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CandidateSelectorTest extends TestCase
{
    use RefreshDatabase;

    private function createCoupon(?array $ruleTree): Coupon
    {
        $code = 'SEL-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Selector Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ]);

        if ($ruleTree !== null) {
            CouponTargeting::create([
                'coupon_id' => $coupon->id,
                'mode' => 'dynamic',
                'require_claim' => true,
                'rule_tree' => $ruleTree,
            ]);
        }

        return $coupon;
    }

    private function createGovernorate(bool $active = true): Governorate
    {
        $country = Country::create(['name' => 'TC', 'status' => true]);

        return Governorate::create([
            'country_id' => $country->id,
            'name' => 'Gov '.Str::random(4),
            'status' => $active,
            'is_fast_shipping_enabled' => false,
        ]);
    }

    /** @return list<int> */
    private function candidateIds(Coupon $coupon): array
    {
        return app(CouponCandidateSelector::class)
            ->queryFor($coupon->fresh('targeting'), 10000)
            ->pluck('users.id')
            ->all();
    }

    public function test_min_completed_orders_narrows_candidates()
    {
        $coupon = $this->createCoupon(['type' => 'min_completed_orders', 'value' => 3]);

        $rich = User::factory()->create();
        CustomerMetrics::create(['user_id' => $rich->id, 'completed_orders' => 5]);
        $poor = User::factory()->create();
        CustomerMetrics::create(['user_id' => $poor->id, 'completed_orders' => 1]);

        $ids = $this->candidateIds($coupon);

        $this->assertContains($rich->id, $ids);
        $this->assertNotContains($poor->id, $ids);
    }

    public function test_max_completed_orders_includes_users_without_metrics()
    {
        $coupon = $this->createCoupon(['type' => 'max_completed_orders', 'value' => 2]);

        $fresh = User::factory()->create(); // no metrics row = 0 orders
        $heavy = User::factory()->create();
        CustomerMetrics::create(['user_id' => $heavy->id, 'completed_orders' => 9]);

        $ids = $this->candidateIds($coupon);

        $this->assertContains($fresh->id, $ids);
        $this->assertNotContains($heavy->id, $ids);
    }

    public function test_area_in_matches_saved_address_any_match()
    {
        $gov = $this->createGovernorate();
        $other = $this->createGovernorate();
        $coupon = $this->createCoupon(['type' => 'area_in', 'value' => [$gov->id]]);

        $local = User::factory()->create();
        Address::create([
            'title' => 'Home',
            'address' => ['street_address' => 'x'],
            'customer_id' => $local->id,
            'governorate_id' => $gov->id,
        ]);

        $remote = User::factory()->create();
        Address::create([
            'title' => 'Home',
            'address' => ['street_address' => 'y'],
            'customer_id' => $remote->id,
            'governorate_id' => $other->id,
        ]);

        $noAddress = User::factory()->create();

        $ids = $this->candidateIds($coupon);

        $this->assertContains($local->id, $ids);
        $this->assertNotContains($remote->id, $ids);
        $this->assertNotContains($noAddress->id, $ids);
    }

    public function test_area_in_ignores_inactive_governorates()
    {
        $gov = $this->createGovernorate(active: false);
        $coupon = $this->createCoupon(['type' => 'area_in', 'value' => [$gov->id]]);

        $user = User::factory()->create();
        Address::create([
            'title' => 'Home',
            'address' => ['street_address' => 'x'],
            'customer_id' => $user->id,
            'governorate_id' => $gov->id,
        ]);

        $this->assertNotContains($user->id, $this->candidateIds($coupon));
    }

    public function test_registered_after_filters_by_created_at()
    {
        $coupon = $this->createCoupon(['type' => 'registered_after', 'value' => now()->subDay()->toDateTimeString()]);

        $new = User::factory()->create(['created_at' => now()]);
        $old = User::factory()->create(['created_at' => now()->subMonth()]);

        $ids = $this->candidateIds($coupon);

        $this->assertContains($new->id, $ids);
        $this->assertNotContains($old->id, $ids);
    }

    public function test_and_intersects_constraints()
    {
        $coupon = $this->createCoupon(['operator' => 'AND', 'rules' => [
            ['type' => 'min_completed_orders', 'value' => 2],
            ['type' => 'has_email', 'value' => true],
        ]]);

        $both = User::factory()->create(['email' => 'both@example.com']);
        CustomerMetrics::create(['user_id' => $both->id, 'completed_orders' => 5]);

        $noOrders = User::factory()->create(['email' => 'orders@example.com']);
        CustomerMetrics::create(['user_id' => $noOrders->id, 'completed_orders' => 0]);

        $ids = $this->candidateIds($coupon);

        $this->assertContains($both->id, $ids);
        $this->assertNotContains($noOrders->id, $ids);
    }

    public function test_or_unions_branches()
    {
        $gov = $this->createGovernorate();
        $coupon = $this->createCoupon(['operator' => 'OR', 'rules' => [
            ['type' => 'min_completed_orders', 'value' => 10],
            ['type' => 'area_in', 'value' => [$gov->id]],
        ]]);

        $buyer = User::factory()->create();
        CustomerMetrics::create(['user_id' => $buyer->id, 'completed_orders' => 50]);

        $local = User::factory()->create();
        CustomerMetrics::create(['user_id' => $local->id, 'completed_orders' => 0]);
        Address::create([
            'title' => 'Home',
            'address' => ['street_address' => 'x'],
            'customer_id' => $local->id,
            'governorate_id' => $gov->id,
        ]);

        $neither = User::factory()->create();
        CustomerMetrics::create(['user_id' => $neither->id, 'completed_orders' => 0]);

        $ids = $this->candidateIds($coupon);

        $this->assertContains($buyer->id, $ids);
        $this->assertContains($local->id, $ids);
        $this->assertNotContains($neither->id, $ids);
    }

    public function test_unknown_rule_stays_broad()
    {
        $coupon = $this->createCoupon(['type' => 'future_rule_xyz', 'value' => 1]);
        $user = User::factory()->create();

        // Selector must not exclude on ignorance; the Engine fail-closes.
        $this->assertContains($user->id, $this->candidateIds($coupon));
    }

    public function test_malformed_area_list_matches_nobody()
    {
        $coupon = $this->createCoupon(['type' => 'area_in', 'value' => ['nope']]);
        $user = User::factory()->create();

        $this->assertNotContains($user->id, $this->candidateIds($coupon));
    }
}
