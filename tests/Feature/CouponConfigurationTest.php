<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\User;
use Tests\TestCase;

class CouponConfigurationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        if (!Schema::hasTable('order_status_history')) {
            Schema::create('order_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->string('old_payment_status')->nullable();
                $table->string('new_payment_status')->nullable();
                $table->string('old_fulfillment_status')->nullable();
                $table->string('new_fulfillment_status')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('changed_by_type')->default('user');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();
            });
        }
        try {
            \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {}
    }

    private function createCoupon(array $overrides = []): Coupon
    {
        $defaults = [
            'code' => 'TEST_' . strtoupper(Str::random(6)),
            'name' => json_encode(['en' => 'Test Coupon']),
            'slug' => 'test-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'limiter' => 100,
            'used' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ];
        return Coupon::create(array_merge($defaults, $overrides));
    }

    /** @test */
    public function validates_public_coupon_configuration()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/coupons/validate-configuration', [
            'coupon_type' => 'public',
            'limiter' => 100,
            'max_uses_per_user' => 1,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.valid', true)
            ->assertJsonPath('success', true);
    }

    /** @test */
    public function rejects_public_coupon_with_multi_use()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/coupons/validate-configuration', [
            'coupon_type' => 'public',
            'limiter' => 100,
            'max_uses_per_user' => 5,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.valid', false);
        $errors = $response->json('data.errors');
        $this->assertNotEmpty($errors);
        $this->assertEquals('max_uses_per_user', $errors[0]['field']);
    }

    /** @test */
    public function allows_assigned_coupon_with_multi_use()
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson('/api/v1/admin/coupons/validate-configuration', [
            'coupon_type' => 'assigned',
            'limiter' => 500,
            'max_uses_per_user' => 5,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.valid', true);
    }

    /** @test */
    public function provides_usage_info_for_public_coupon()
    {
        $coupon = $this->createCoupon([
            'code' => 'PUBLIC10',
            'limiter' => 100,
            'used' => 25,
        ]);

        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/admin/coupons/{$coupon->id}/usage-info");

        $response->assertStatus(200)
            ->assertJsonPath('data.coupon_type', 'public')
            ->assertJsonPath('data.current_usage', 25)
            ->assertJsonPath('data.global_limit', 100)
            ->assertJsonPath('data.remaining_capacity', 75)
            ->assertJsonPath('data.is_multi_use_per_user', false);
    }

    /** @test */
    public function provides_usage_info_for_assigned_coupon()
    {
        $coupon = $this->createCoupon([
            'code' => 'VIP20',
            'limiter' => 500,
            'used' => 30,
        ]);

        // Create assignments
        for ($i = 0; $i < 10; $i++) {
            $user = User::factory()->create();
            CouponAssignment::create([
                'coupon_id' => $coupon->id,
                'user_id' => $user->id,
                'max_uses' => 3,
                'used' => 0,
            ]);
        }

        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->getJson("/api/v1/admin/coupons/{$coupon->id}/usage-info");

        $response->assertStatus(200)
            ->assertJsonPath('data.coupon_type', 'assigned')
            ->assertJsonPath('data.assignment_info.total_assignments', 10)
            ->assertJsonPath('data.assignment_info.max_uses_per_user', 3)
            ->assertJsonPath('data.assignment_info.total_possible_redemptions', 30);
    }

    /** @test */
    public function suggests_conversion_for_public_to_multi_use()
    {
        $coupon = $this->createCoupon(['code' => 'PUBLIC10']);

        $admin = User::factory()->create(['type' => 'admin']);
        Sanctum::actingAs($admin);

        $response = $this->postJson("/api/v1/admin/coupons/{$coupon->id}/suggest-fix", [
            'desired_behavior' => 'multi_use_per_user',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.recommended_action', 'convert_to_assigned')
            ->assertJsonStructure(['data' => ['steps', 'example_code']]);
    }

    /** @test */
    public function model_correctly_identifies_public_vs_assigned()
    {
        $publicCoupon = $this->createCoupon();
        $this->assertTrue($publicCoupon->isPublic());
        $this->assertFalse($publicCoupon->isMultiUsePerUser());

        $assignedCoupon = $this->createCoupon();
        $user = User::factory()->create();
        CouponAssignment::create([
            'coupon_id' => $assignedCoupon->id,
            'user_id' => $user->id,
            'max_uses' => 5,
        ]);

        $assignedCoupon->refresh();
        $this->assertFalse($assignedCoupon->isPublic());
        $this->assertTrue($assignedCoupon->isMultiUsePerUser());
    }

    /** @test */
    public function model_usage_description_reflects_configuration()
    {
        $publicCoupon = $this->createCoupon(['limiter' => 100]);
        $this->assertStringContainsString('Single use per customer', $publicCoupon->getUsageDescription());
        $this->assertStringContainsString('global limit: 100', $publicCoupon->getUsageDescription());

        $assignedCoupon = $this->createCoupon();
        $user = User::factory()->create();
        CouponAssignment::create([
            'coupon_id' => $assignedCoupon->id,
            'user_id' => $user->id,
            'max_uses' => 3,
        ]);

        $assignedCoupon->refresh();
        $this->assertStringContainsString('Up to 3 uses', $assignedCoupon->getUsageDescription());
    }
}
