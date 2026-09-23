<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CouponSuggestFixTest extends TestCase
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

    private function admin(): User
    {
        $admin = User::factory()->create(['type' => 'admin']);
        Permission::firstOrCreate(['name' => 'view-coupons', 'guard_name' => 'api']);
        $admin->givePermissionTo('view-coupons');
        Sanctum::actingAs($admin);

        return $admin;
    }

    private function assign(Coupon $coupon, int $maxUses = 1): CouponAssignment
    {
        return CouponAssignment::create([
            'coupon_id' => $coupon->id,
            'user_id' => User::factory()->create()->id,
            'max_uses' => $maxUses,
            'used' => 0,
        ]);
    }

    private function suggest(Coupon $coupon, string $behavior)
    {
        $this->admin();

        return $this->postJson("/api/v1/admin/coupons/{$coupon->id}/suggest-fix", [
            'desired_behavior' => $behavior,
        ]);
    }

    private function assertBusinessContract($response, string $action): array
    {
        $response->assertStatus(200);

        $data = $response->json('data');

        $this->assertSame($action, $data['recommended_action']);
        $this->assertIsString($data['summary']);
        $this->assertNotSame('', $data['summary']);
        $this->assertIsArray($data['steps']);
        $this->assertIsArray($data['warnings']);
        $this->assertIsString($data['expected_result']);

        // No implementation details may leak anywhere in the payload.
        $raw = json_encode($data);
        foreach (['::create', '::where', '->update', '->create', 'SELECT', 'INSERT', 'UPDATE ', 'CouponAssignment::', 'Eloquent', 'Repository'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "Response leaks implementation detail [{$needle}].");
        }
        $this->assertArrayNotHasKey('example_code', $data);

        foreach ($data['steps'] as $step) {
            $this->assertIsString($step);
        }
        foreach ($data['warnings'] as $warning) {
            $this->assertIsString($warning);
        }

        return $data;
    }

    /** @test Case 1: public coupon requesting multi-use. */
    public function public_coupon_multi_use_recommends_conversion_with_actionable_steps()
    {
        $coupon = $this->createCoupon();

        $data = $this->assertBusinessContract(
            $this->suggest($coupon, 'multi_use_per_user'),
            'convert_to_assigned'
        );

        $this->assertNotEmpty($data['steps']);
        $this->assertStringContainsString('assignment', strtolower(implode(' ', $data['steps'])));
        // Converting public -> assigned must warn about the audience change.
        $this->assertNotEmpty($data['warnings']);
    }

    /** @test Case 2: assigned max_uses=1 requesting multi-use. */
    public function assigned_single_use_multi_use_recommends_update()
    {
        $coupon = $this->createCoupon();
        $this->assign($coupon, 1);

        $data = $this->assertBusinessContract(
            $this->suggest($coupon, 'multi_use_per_user'),
            'update_assignments'
        );

        $this->assertNotEmpty($data['steps']);
    }

    /** @test Case 3: assigned max_uses>1 requesting multi-use. */
    public function assigned_multi_use_multi_use_needs_no_change()
    {
        $coupon = $this->createCoupon();
        $this->assign($coupon, 5);

        $data = $this->assertBusinessContract(
            $this->suggest($coupon, 'multi_use_per_user'),
            'no_change_needed'
        );

        $this->assertSame([], $data['steps']);
    }

    /** @test Case 4: public coupon requesting single-use. */
    public function public_coupon_single_use_needs_no_change()
    {
        $coupon = $this->createCoupon();

        $data = $this->assertBusinessContract(
            $this->suggest($coupon, 'single_use_per_user'),
            'no_change_needed'
        );

        $this->assertSame([], $data['steps']);
    }

    /** @test Case 5: assigned max_uses=1 requesting single-use. */
    public function assigned_single_use_single_use_needs_no_change()
    {
        $coupon = $this->createCoupon();
        $this->assign($coupon, 1);

        $data = $this->assertBusinessContract(
            $this->suggest($coupon, 'single_use_per_user'),
            'no_change_needed'
        );

        $this->assertSame([], $data['steps']);
    }

    /** @test Case 6: assigned max_uses>1 requesting single-use. */
    public function assigned_multi_use_single_use_recommends_update_without_removal()
    {
        $coupon = $this->createCoupon();
        $this->assign($coupon, 5);

        $data = $this->assertBusinessContract(
            $this->suggest($coupon, 'single_use_per_user'),
            'update_assignments'
        );

        $this->assertNotEmpty($data['steps']);
        // Destructive removal must never be the recommendation.
        $this->assertStringNotContainsString('remove', strtolower(implode(' ', $data['steps'])));
        $this->assertNotEmpty($data['warnings']);
    }

    /** @test Case 7: targeting + require_claim must surface as warnings, not contradictions. */
    public function targeting_and_claim_settings_surface_as_warnings()
    {
        $coupon = $this->createCoupon();
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ]);

        $data = $this->assertBusinessContract(
            $this->suggest($coupon, 'multi_use_per_user'),
            'convert_to_assigned'
        );

        $warnings = strtolower(implode(' ', $data['warnings']));
        $this->assertStringContainsString('claim', $warnings);
        $this->assertStringContainsString('dynamic', $warnings);
    }

    /** @test Case 8: invalid desired_behavior. */
    public function invalid_desired_behavior_is_rejected_with_allowed_values()
    {
        $coupon = $this->createCoupon();
        $this->admin();

        $response = $this->postJson("/api/v1/admin/coupons/{$coupon->id}/suggest-fix", [
            'desired_behavior' => 'CouponAssignment::create',
        ]);

        $response->assertStatus(400)
            ->assertJsonPath('success', false)
            ->assertJsonPath('data.allowed_values', ['multi_use_per_user', 'single_use_per_user']);
    }
}
