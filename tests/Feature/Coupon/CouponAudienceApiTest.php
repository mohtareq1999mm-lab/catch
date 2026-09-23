<?php

namespace Tests\Feature\Coupon;

use App\Services\Coupon\Audience\CouponAudienceResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * API audience-state representation: computed audience_type +
 * targeting.mode exposure without new columns or logic moves.
 */
class CouponAudienceApiTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'AUD-' . strtoupper(Str::random(6)),
            'slug' => 'aud-' . strtolower(Str::random(6)),
            'name' => 'Audience API coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));
    }

    private function admin(): User
    {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view-coupons', 'guard_name' => 'api']);
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->givePermissionTo('view-coupons');

        return $admin;
    }

    public function test_audience_type_covers_all_four_states()
    {
        $resolver = app(CouponAudienceResolver::class);

        $public = $this->makeCoupon();
        $this->assertSame('PUBLIC', $resolver->audienceType($public));

        $assigned = $this->makeCoupon();
        CouponAssignment::create(['coupon_id' => $assigned->id, 'user_id' => User::factory()->create()->id, 'max_uses' => 1]);
        $this->assertSame('ASSIGNED', $resolver->audienceType($assigned));

        $targeted = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $targeted->id, 'mode' => 'dynamic', 'rule_tree' => null]);
        $this->assertSame('TARGETED', $resolver->audienceType($targeted));

        $both = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $both->id, 'mode' => 'assignment_and_dynamic', 'rule_tree' => null]);
        CouponAssignment::create(['coupon_id' => $both->id, 'user_id' => User::factory()->create()->id, 'max_uses' => 1]);
        $this->assertSame('ASSIGNED_AND_TARGETED', $resolver->audienceType($both));
    }

    public function test_admin_show_exposes_audience_type_and_targeting_mode()
    {
        Sanctum::actingAs($this->admin());

        $coupon = $this->makeCoupon();
        CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => 'assignment_or_dynamic', 'rule_tree' => null]);

        $response = $this->getJson("/api/v1/coupons/{$coupon->id}");

        $response->assertOk()
            ->assertJsonPath('data.audience_type', 'TARGETED')
            ->assertJsonPath('data.targeting_mode', 'assignment_or_dynamic');
    }

    public function test_admin_show_without_targeting_reports_public_and_null_mode()
    {
        Sanctum::actingAs($this->admin());

        $coupon = $this->makeCoupon();

        $response = $this->getJson("/api/v1/coupons/{$coupon->id}");

        $response->assertOk()
            ->assertJsonPath('data.audience_type', 'PUBLIC')
            ->assertJsonPath('data.targeting_mode', null);
    }

    public function test_admin_index_lists_audience_type_per_row()
    {
        Sanctum::actingAs($this->admin());

        $assigned = $this->makeCoupon();
        CouponAssignment::create(['coupon_id' => $assigned->id, 'user_id' => User::factory()->create()->id, 'max_uses' => 1]);

        $response = $this->getJson('/api/v1/coupons?limit=50');

        $response->assertOk();
        $rows = collect($response->json('data.data'));
        $this->assertSame('ASSIGNED', $rows->firstWhere('id', $assigned->id)['audience_type']);
    }
}
