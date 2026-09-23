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
 * Catalog = PUBLIC + TARGETED (ineligible targeted stay visible).
 * Assignment-only grants are excluded (private to assignees via My Coupons).
 * Matrix (authenticated): public → shown + code; targeted + eligible +
 * no-claim → shown + code; targeted + eligible + claim → shown, code
 * hidden; targeted + ineligible → SHOWN, code hidden, action null.
 * Guests: public + targeted rows, codes hidden, never eligible.
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
        } else {
            // Simulate a fresh guest request: the container memoizes guard
            // users across calls within one test process.
            $this->defaultHeaders = [];
            auth()->forgetGuards();
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

    /** @test Case 4: targeted + ineligible → VISIBLE, not usable. */
    public function targeted_ineligible_coupon_visible_without_code_or_action()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', true, ['type' => 'min_completed_orders', 'value' => 500]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        $response = $this->index($user);
        $rows = $this->rows($response);

        $this->assertArrayHasKey($coupon->id, $rows);
        $this->assertSame('targeted', $rows[$coupon->id]['visibility']);
        $this->assertFalse($rows[$coupon->id]['eligible']);
        $this->assertIsBool($rows[$coupon->id]['eligible']);
        $this->assertTrue($rows[$coupon->id]['requires_claim']);
        $this->assertNull($rows[$coupon->id]['code']);
        $this->assertNull($rows[$coupon->id]['action']);

        foreach (['coupon_code', 'rule_tree', 'assignments', 'targeting'] as $leak) {
            $this->assertArrayNotHasKey($leak, $rows[$coupon->id]);
        }
        $this->assertStringNotContainsString($coupon->code, $response->getContent());
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

    /** @test Case 7: assignment-only → excluded (private grants). */
    public function assignment_only_coupon_excluded_from_catalog()
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

    /** @test Guests: public + targeted visible, codes hidden, never eligible. */
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
        // Guests browse only: no claim/apply affordance without identity.
        $this->assertNull($rows[$public->id]['action']);

        $this->assertArrayHasKey($targeted->id, $rows);
        $this->assertSame('targeted', $rows[$targeted->id]['visibility']);
        $this->assertFalse($rows[$targeted->id]['eligible']);
        $this->assertNull($rows[$targeted->id]['code']);
        $this->assertNull($rows[$targeted->id]['action']);
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

    /** @test Eligible + claim-required → claim action, claimable status. */
    public function eligible_claim_coupon_reports_claim_action()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', true, ['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        $rows = $this->rows($this->index($user));

        $this->assertTrue($rows[$coupon->id]['eligible']);
        $this->assertSame('claimable', $rows[$coupon->id]['claim_status']);
        $this->assertSame('claim', $rows[$coupon->id]['action']);
        $this->assertNull($rows[$coupon->id]['code']);
    }

    /** @test Eligible + no claim → apply action with visible code. */
    public function eligible_no_claim_coupon_reports_apply_action()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', false, ['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);

        $rows = $this->rows($this->index($user));

        $this->assertTrue($rows[$coupon->id]['eligible']);
        $this->assertSame('not_required', $rows[$coupon->id]['claim_status']);
        $this->assertSame('apply', $rows[$coupon->id]['action']);
        $this->assertSame($coupon->code, $rows[$coupon->id]['code']);
    }

    /** @test Guest sees targeted rows as ineligible without code or action. */
    public function guest_sees_targeted_without_code_action_or_eligibility()
    {
        $targeted = $this->createCoupon();
        $this->target($targeted, 'dynamic', true, ['type' => 'min_completed_orders', 'value' => 0]);

        $rows = $this->rows($this->index());

        $this->assertArrayHasKey($targeted->id, $rows);
        $this->assertSame('targeted', $rows[$targeted->id]['visibility']);
        $this->assertFalse($rows[$targeted->id]['eligible']);
        $this->assertNull($rows[$targeted->id]['code']);
        $this->assertNull($rows[$targeted->id]['action']);
    }

    /** @test Targeting change retires cached discovery responses. */
    public function targeting_mutation_invalidates_discovery_cache()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();

        $before = $this->rows($this->index($user));
        $this->assertSame('public', $before[$coupon->id]['visibility']);

        $admin = User::factory()->create(['type' => 'admin']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-coupon', 'guard_name' => 'api']);
        $admin->givePermissionTo('update-coupon');
        Sanctum::actingAs($admin);

        $this->putJson("/api/v1/admin/coupons/{$coupon->id}/targeting", [
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ])->assertOk();

        $after = $this->rows($this->index($user));
        $this->assertSame('targeted', $after[$coupon->id]['visibility']);
        $this->assertTrue($after[$coupon->id]['requires_claim']);
    }

    /** @test Coupon mutation bumps the discovery version. */
    public function coupon_mutation_bumps_discovery_version()
    {
        $this->assertSame(0, \App\Services\Coupon\Discovery\CouponDiscoveryCache::version());

        $this->createCoupon();

        $this->assertSame(1, \App\Services\Coupon\Discovery\CouponDiscoveryCache::version());
    }

    /** @test E2E §27: targeting change flips ineligible → eligible in catalog. */
    public function targeting_change_flips_eligibility_in_catalog()
    {
        $coupon = $this->createCoupon();
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 1]);

        // Initially ineligible (threshold 500): visible, unusable.
        $this->target($coupon, 'dynamic', true, ['type' => 'min_completed_orders', 'value' => 500]);
        $before = $this->rows($this->index($user));
        $this->assertFalse($before[$coupon->id]['eligible']);
        $this->assertNull($before[$coupon->id]['code']);
        $this->assertNull($before[$coupon->id]['action']);

        // Admin relaxes targeting via the real endpoint.
        $admin = User::factory()->create(['type' => 'admin']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-coupon', 'guard_name' => 'api']);
        $admin->givePermissionTo('update-coupon');
        Sanctum::actingAs($admin);
        $this->putJson("/api/v1/admin/coupons/{$coupon->id}/targeting", [
            'mode' => 'dynamic',
            'require_claim' => true,
            'rule_tree' => ['type' => 'min_completed_orders', 'value' => 0],
        ])->assertOk();

        // Same customer: still visible, now eligible with claim action.
        $after = $this->rows($this->index($user));
        $this->assertSame('targeted', $after[$coupon->id]['visibility']);
        $this->assertTrue($after[$coupon->id]['eligible']);
        $this->assertTrue($after[$coupon->id]['requires_claim']);
        $this->assertNull($after[$coupon->id]['code']);
        $this->assertSame('claim', $after[$coupon->id]['action']);
        $this->assertSame('claimable', $after[$coupon->id]['claim_status']);
    }

    /** @test E2E §28: assignment privacy — B excluded, A served via mine. */
    public function assignment_privacy_end_to_end()
    {
        $coupon = $this->createCoupon();
        $customerA = User::factory()->create();
        $customerB = User::factory()->create();
        CouponAssignment::create([
            'coupon_id' => $coupon->id, 'user_id' => $customerA->id,
            'max_uses' => 3, 'used' => 0,
        ]);

        $rowsB = $this->rows($this->index($customerB));
        $this->assertArrayNotHasKey($coupon->id, $rowsB);

        Sanctum::actingAs($customerA);
        $mine = $this->getJson('/api/v1/general/coupons/mine');
        $mine->assertOk();
        $assignments = collect($mine->json('data.assignments'));
        $this->assertTrue($assignments->contains(fn ($a) => (int) $a['coupon_id'] === $coupon->id));
        $this->assertSame($coupon->code, $assignments->firstWhere('coupon_id', $coupon->id)['code']);
    }

    /** @test E2E §30: targeting deletion with no assignments → public. */
    public function targeting_deletion_returns_coupon_to_public()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', true, ['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();

        $targeted = $this->rows($this->index($user));
        $this->assertSame('targeted', $targeted[$coupon->id]['visibility']);

        $admin = User::factory()->create(['type' => 'admin']);
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'update-coupon', 'guard_name' => 'api']);
        $admin->givePermissionTo('update-coupon');
        Sanctum::actingAs($admin);
        $this->deleteJson("/api/v1/admin/coupons/{$coupon->id}/targeting")->assertOk();

        $public = $this->rows($this->index($user));
        $this->assertSame('public', $public[$coupon->id]['visibility']);
        $this->assertFalse($public[$coupon->id]['requires_claim']);
        $this->assertSame($coupon->code, $public[$coupon->id]['code']);

        $guest = $this->rows($this->index());
        $this->assertSame('public', $guest[$coupon->id]['visibility']);
        $this->assertNull($guest[$coupon->id]['code']);
    }

    /** @test E2E §31: targeting + assignments → targeted, no assignment leak. */
    public function targeting_takes_precedence_over_assignments()
    {
        $coupon = $this->createCoupon();
        $this->target($coupon, 'dynamic', false, ['type' => 'min_completed_orders', 'value' => 0]);
        $user = User::factory()->create();
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => 2]);
        $other = User::factory()->create();
        CouponAssignment::create([
            'coupon_id' => $coupon->id, 'user_id' => $other->id,
            'max_uses' => 5, 'used' => 1,
        ]);

        $rows = $this->rows($this->index($user));

        $this->assertSame('targeted', $rows[$coupon->id]['visibility']);
        $this->assertTrue($rows[$coupon->id]['eligible']);
        $this->assertSame($coupon->code, $rows[$coupon->id]['code']);

        $raw = json_encode($rows[$coupon->id]);
        $this->assertStringNotContainsString((string) $other->id, $raw);
        $this->assertArrayNotHasKey('assignments', $rows[$coupon->id]);
    }
}
