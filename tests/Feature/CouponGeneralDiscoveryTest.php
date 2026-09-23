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
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Canonical discovery policy on GET /api/v1/general/coupons.
 *
 * Matrix (authenticated): public → shown + code; targeted + eligible +
 * no-claim → shown + code; targeted + eligible + claim → shown, code
 * hidden; targeted + ineligible → hidden; assignment-only → hidden.
 * Guests: pure-public coupons only, codes hidden, targeting never evaluated.
 */
class CouponGeneralDiscoveryTest extends TestCase
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
        $code = 'GEN-' . strtoupper(Str::random(6));

        return Coupon::create(array_merge([
            'code' => $code,
            'name' => json_encode(['en' => 'General Coupon']),
            'slug' => 'gen-' . Str::random(6),
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'limiter' => 100,
            'used' => 0,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
        ], $overrides));
    }

    private function target(Coupon $coupon, string $mode, bool $requireClaim, ?array $ruleTree = null): void
    {
        CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => $mode,
            'require_claim' => $requireClaim,
            'rule_tree' => $ruleTree,
        ]);
    }

    private function index(?User $user = null)
    {
        if ($user) {
            Sanctum::actingAs($user);
        }

        return $this->getJson('/api/v1/general/coupons');
    }

    private function rows($response): array
    {
        $response->assertOk();

        return collect($response->json('data'))->keyBy('id')->all();
    }

    /** @test Case 1: public coupon, authenticated → shown with code. */
    public function public_coupon_shown_with_code_to_customer()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        $rows = $this->rows($this->index($user));

        $this->assertArrayHasKey($coupon->id, $rows);
        $this->assertSame('public', $rows[$coupon->id]['visibility']);
        $this->assertFalse($rows[$coupon->id]['requires_claim']);
        $this->assertIsBool($rows[$coupon->id]['requires_claim']);
        $this->assertSame($coupon->code, $rows[$coupon->id]['code']);
    }

    /** @test Case 2: targeted + eligible + claim → shown, code hidden, no leak. */
    public function targeted_claim_coupon_shown_without_code()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', true, ['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        $response = $this->index($user);
        $rows = $this->rows($response);

        $this->assertArrayHasKey($coupon->id, $rows);
        $this->assertSame('targeted', $rows[$coupon->id]['visibility']);
        $this->assertTrue($rows[$coupon->id]['requires_claim']);
        $this->assertNull($rows[$coupon->id]['code']);

        foreach (['coupon_code', 'rule_tree', 'assignments', 'targeting'] as $leak) {
            $this->assertArrayNotHasKey($leak, $rows[$coupon->id]);
        }
        $this->assertStringNotContainsString($coupon->code, $response->getContent());
    }

    /** @test Case 3: targeted + eligible + no claim → shown with code. */
    public function targeted_no_claim_coupon_shown_with_code()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', false, ['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        $rows = $this->rows($this->index($user));

        $this->assertArrayHasKey($coupon->id, $rows);
        $this->assertSame('targeted', $rows[$coupon->id]['visibility']);
        $this->assertFalse($rows[$coupon->id]['requires_claim']);
        $this->assertSame($coupon->code, $rows[$coupon->id]['code']);
    }

    /** @test Case 4: targeted + ineligible → hidden. */
    public function targeted_ineligible_coupon_hidden()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', true, ['type' => 'min_completed_orders', 'value' => 500]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $rows = $this->rows($this->index($user));

        $this->assertArrayNotHasKey($coupon->id, $rows);
        $this->assertStringNotContainsString($coupon->code, $this->getJson('/api/v1/general/coupons')->getContent());
    }

    /** @test Case 5/6: expired + disabled public → hidden. */
    public function expired_and_disabled_public_coupons_hidden()
    {
        $expired = $this->createCoupon(['end_date' => now()->subDay()->toDateString()]);
        $disabled = $this->createCoupon(['status' => false]);
        $visible = $this->createCoupon();
        $user = User::factory()->create();

        $rows = $this->rows($this->index($user));

        $this->assertArrayNotHasKey($expired->id, $rows);
        $this->assertArrayNotHasKey($disabled->id, $rows);
        $this->assertArrayHasKey($visible->id, $rows);
    }

    /** @test Case 7: assignment-only → hidden from general discovery. */
    public function assignment_only_coupon_hidden_from_general_discovery()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();
        CouponAssignment::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id,
            'max_uses' => 3, 'used' => 0,
        ]);

        $rows = $this->rows($this->index($user));

        $this->assertArrayNotHasKey($coupon->id, $rows);
    }

    /** @test Guests: public only, codes hidden, no targeting evaluation. */
    public function guest_sees_public_without_codes_and_no_targeted()
    {
        $public = $this->createCoupon();
        $targeted = $this->createCoupon();
        $this->target($targeted, 'dynamic', false, ['type' => 'min_completed_orders', 'value' => 0]);

        $rows = $this->rows($this->index());

        $this->assertArrayHasKey($public->id, $rows);
        $this->assertSame('public', $rows[$public->id]['visibility']);
        $this->assertFalse($rows[$public->id]['requires_claim']);
        $this->assertNull($rows[$public->id]['code']);
        $this->assertArrayNotHasKey($targeted->id, $rows);
    }

    /** @test No duplicates: targeted + assigned coupon appears exactly once. */
    public function coupon_appears_exactly_once()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'assignment', false);
        $user = User::factory()->create();
        CouponAssignment::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id,
            'max_uses' => 2, 'used' => 0,
        ]);

        $response = $this->index($user);
        $rows = $this->rows($response);

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame(1, count(array_filter($ids, fn ($id) => $id === $coupon->id)));
        $this->assertSame('targeted', $rows[$coupon->id]['visibility']);
    }

    /** @test Existing filters keep working alongside discovery rules. */
    public function search_filter_still_applies()
    {
        $this->createCoupon(['code' => 'MATCHME1', 'name' => json_encode(['en' => 'Zebra Special'])]);
        $this->createCoupon(['code' => 'OTHER99', 'name' => json_encode(['en' => 'Plain Deal'])]);
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/general/coupons?search=Zebra');
        $rows = $this->rows($response);

        $this->assertCount(1, $rows);
        $this->assertSame('MATCHME1', reset($rows)['code']);
    }
}
