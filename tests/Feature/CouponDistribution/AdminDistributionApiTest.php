<?php

namespace Tests\Feature\CouponDistribution;

use App\Services\Coupon\Distribution\Messaging\FakeCouponEventTransport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdminDistributionApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $fake = new FakeCouponEventTransport();
        $this->app->singleton(FakeCouponEventTransport::class, fn () => $fake);
    }

    private function createDynamicCoupon(): Coupon
    {
        $code = 'ADM-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Admin Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic', 'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);

        return $coupon;
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Permission::firstOrCreate(['name' => 'update-coupon', 'guard_name' => 'api']);
        $admin->givePermissionTo('update-coupon');

        return $admin;
    }

    private function distributeUrl(int $id): string
    {
        return "/api/v1/admin/coupons/{$id}/distribute";
    }

    public function test_unauthenticated_is_rejected()
    {
        $coupon = $this->createDynamicCoupon();

        $this->postJson($this->distributeUrl($coupon->id), [])->assertUnauthorized();
    }

    public function test_without_permission_is_forbidden()
    {
        $coupon = $this->createDynamicCoupon();
        Sanctum::actingAs(User::factory()->create());

        $this->postJson($this->distributeUrl($coupon->id), [])
            ->assertForbidden();
    }

    public function test_missing_coupon_is_404()
    {
        Sanctum::actingAs($this->admin());

        $this->postJson($this->distributeUrl(999999), [])->assertNotFound();
    }

    public function test_non_dynamic_coupon_is_422()
    {
        $code = 'PUB-'.Str::random(8);
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Public',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);

        Sanctum::actingAs($this->admin());

        $response = $this->postJson($this->distributeUrl($coupon->id), []);
        $response->assertStatus(422);
        $this->assertSame('not_distributable', $response->json('data.reason'));
    }

    public function test_manual_distribution_accepted_with_run()
    {
        $coupon = $this->createDynamicCoupon();
        Sanctum::actingAs($this->admin());

        $response = $this->postJson($this->distributeUrl($coupon->id), ['audience_cap' => 100]);
        $response->assertStatus(202)->assertJsonPath('success', true);

        $run = $response->json('data.run');
        $this->assertSame($coupon->id, $run['coupon_id']);
        $this->assertSame('pending', $run['status']);
        $this->assertArrayNotHasKey('user_id', $run);
    }

    public function test_completed_run_accepts_new_manual_run()
    {
        $coupon = $this->createDynamicCoupon();
        Sanctum::actingAs($this->admin());

        $first = $this->postJson($this->distributeUrl($coupon->id), []);
        $first->assertStatus(202);

        \App\Models\CouponDistributionRun::query()->update(['status' => 'completed']);

        $second = $this->postJson($this->distributeUrl($coupon->id), []);
        $second->assertStatus(202);
        $this->assertNotSame(
            $first->json('data.run.id'),
            $second->json('data.run.id')
        );
    }

    public function test_double_submit_returns_409_with_live_run()
    {
        $coupon = $this->createDynamicCoupon();
        Sanctum::actingAs($this->admin());

        $this->postJson($this->distributeUrl($coupon->id), [])->assertStatus(202);

        // Force the run into running (consumer picked it up).
        \App\Models\CouponDistributionRun::query()->update(['status' => 'running']);

        $response = $this->postJson($this->distributeUrl($coupon->id), []);
        $response->assertStatus(409);
        $this->assertSame('already_running', $response->json('data.reason'));
    }

    public function test_audience_cap_is_validated()
    {
        $coupon = $this->createDynamicCoupon();
        Sanctum::actingAs($this->admin());

        $this->postJson($this->distributeUrl($coupon->id), ['audience_cap' => -5])
            ->assertStatus(422);
    }

    public function test_distributions_index_and_show_expose_counters_only()
    {
        $coupon = $this->createDynamicCoupon();
        Sanctum::actingAs($this->admin());

        $this->postJson($this->distributeUrl($coupon->id), [])->assertStatus(202);

        $index = $this->getJson("/api/v1/admin/coupons/{$coupon->id}/distributions");
        $index->assertOk();
        $this->assertCount(1, $index->json('data.data'));

        $runId = $index->json('data.data.0.id');
        $show = $this->getJson("/api/v1/admin/coupons/{$coupon->id}/distributions/{$runId}");
        $show->assertOk();
        $this->assertSame($runId, $show->json('data.run.id'));
        $this->assertArrayHasKey('recipient_breakdown', $show->json('data'));

        // No per-user PII anywhere in the operational surface.
        $this->assertStringNotContainsString('@', $show->getContent());
    }
}
