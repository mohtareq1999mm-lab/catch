<?php

declare(strict_types=1);

namespace Tests\Feature\CouponDistribution;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\CustomerMetrics;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Coupon discovery code-exposure contract (catch-safe: transactional rows
 * only, never RefreshDatabase — the shared `catch` database must survive).
 *
 * Approved rule (CouponDiscoveryPolicy::decide, single decision point):
 *   public + eligible + (no claim required OR active claim held)
 *   → canonical coupon code exposed; otherwise code is null.
 *
 * Reachability notes (proven by construction, not assumptions):
 * - visibility=public ⟺ no targeting row ⟹ requires_claim=false and the
 *   Engine verdict is always eligible. Hence "public + requires_claim=true"
 *   and "public + ineligible" are unreachable states; their reachable
 *   analogues (targeted claim-required / targeted ineligible) are pinned
 *   hidden instead, and non-public behavior is unchanged.
 */
class CouponDiscoveryCodeExposureTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const AVAILABLE = '/api/v1/general/coupons/available';
    private const CATALOG = '/api/v1/general/coupons';
    private const MINE = '/api/v1/general/coupons/mine';

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        Cache::flush();

        $this->createAllTestTables();
    }

    protected function tearDown(): void
    {
        \Mockery::close();
        parent::tearDown();
    }

    private function makeUser(): User
    {
        return User::factory()->create();
    }

    private function makePublicCoupon(): Coupon
    {
        $code = 'PUB-' . Str::upper(Str::random(8));

        return Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Public Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
    }

    private function makeTargetedCoupon(array $ruleTree, bool $requireClaim = true): Coupon
    {
        $code = 'TGT-' . Str::upper(Str::random(8));
        $coupon = Coupon::create([
            'code' => $code, 'slug' => Str::slug($code), 'name' => 'Targeted Coupon',
            'discount_type' => 'percentage', 'discount' => 10,
            'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
        ]);
        CouponTargeting::create([
            'coupon_id' => $coupon->id, 'mode' => 'dynamic',
            'require_claim' => $requireClaim, 'rule_tree' => $ruleTree,
        ]);

        return $coupon;
    }

    private function setCompletedOrders(User $user, int $count): void
    {
        CustomerMetrics::create(['user_id' => $user->id, 'completed_orders' => $count]);
    }

    private function availableItemFor(User $user, int $couponId): ?array
    {
        Sanctum::actingAs($user);

        $items = $this->getJson(self::AVAILABLE)->assertOk()->json('data.data');

        return collect($items)->firstWhere('id', $couponId);
    }

    // -----------------------------------------------------------------
    // Test 1 — public + eligible + no claim required → canonical code
    // -----------------------------------------------------------------

    public function test_public_eligible_no_claim_exposes_canonical_code(): void
    {
        $coupon = $this->makePublicCoupon();
        $user = $this->makeUser();

        $claimsBefore = CouponClaim::count();
        $usagesBefore = DB::table('coupon_usages')->count();
        $reservationsBefore = DB::table('coupon_reservations')->count();

        $item = $this->availableItemFor($user, $coupon->id);

        $this->assertNotNull($item);
        $this->assertSame('public', $item['visibility']);
        $this->assertFalse($item['requires_claim']);
        // NOTE: /available carries no `eligible` key — inclusion in the
        // personalized feed already means Engine-eligible (ineligible rows
        // are filtered, never rendered).
        $this->assertSame('not_required', $item['claim_status']);
        // Canonical source: the stored code resolves through Coupon::byCode.
        $this->assertSame(Coupon::byCode($coupon->code)->first()->code, $item['code']);
        $this->assertSame($coupon->code, $item['code']);

        // Discovery is advisory-only: no claim, usage, or reservation created.
        $this->assertSame($claimsBefore, CouponClaim::count());
        $this->assertSame($usagesBefore, DB::table('coupon_usages')->count());
        $this->assertSame($reservationsBefore, DB::table('coupon_reservations')->count());
    }

    // -----------------------------------------------------------------
    // Test 2 — claim required + no active claim → code hidden
    // -----------------------------------------------------------------

    public function test_claim_required_without_active_claim_hides_code(): void
    {
        $coupon = $this->makeTargetedCoupon(['type' => 'min_completed_orders', 'value' => 0], true);
        $user = $this->makeUser();
        $this->setCompletedOrders($user, 2);

        $item = $this->availableItemFor($user, $coupon->id);

        $this->assertNotNull($item);
        $this->assertTrue($item['requires_claim']);
        $this->assertSame('claimable', $item['claim_status']);
        $this->assertSame('claim', $item['action']);
        $this->assertNull($item['code']);
    }

    // -----------------------------------------------------------------
    // Test 3 — claim required + ACTIVE claim → non-public stays hidden
    // -----------------------------------------------------------------

    public function test_active_claim_on_targeted_coupon_keeps_code_hidden(): void
    {
        $coupon = $this->makeTargetedCoupon(['type' => 'min_completed_orders', 'value' => 0], true);
        $user = $this->makeUser();
        $this->setCompletedOrders($user, 2);
        $claim = CouponClaim::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'claimed_at' => now(),
        ]);

        $item = $this->availableItemFor($user, $coupon->id);

        $this->assertNotNull($item);
        $this->assertSame('claimed', $item['claim_status']);
        $this->assertSame('apply', $item['action']);
        $this->assertSame($claim->id, $item['claim_id']);
        // Owner-safe shell: targeted codes surface via /mine, never discovery.
        $this->assertNull($item['code']);
    }

    // -----------------------------------------------------------------
    // Test 4 — ineligible → code hidden (shown in catalog, never the code)
    // -----------------------------------------------------------------

    public function test_ineligible_coupon_hides_code_in_catalog(): void
    {
        $coupon = $this->makeTargetedCoupon(['type' => 'min_completed_orders', 'value' => 500], true);
        $user = $this->makeUser();
        $this->setCompletedOrders($user, 1);

        // Personalized feed excludes ineligible rows entirely.
        Sanctum::actingAs($user);
        $this->assertNull($this->availableItemFor($user, $coupon->id));

        // General catalog still lists it (visibility), but never the code.
        $rows = $this->getJson(self::CATALOG)->assertOk()->json('data');
        $row = collect($rows)->firstWhere('id', $coupon->id);

        $this->assertNotNull($row);
        $this->assertFalse($row['eligible']);
        $this->assertNull($row['action']);
        $this->assertNull($row['code']);
        $this->assertStringNotContainsString($coupon->code, (string) json_encode($rows));
    }

    // -----------------------------------------------------------------
    // Test 5 — user isolation (incl. cached paths)
    // -----------------------------------------------------------------

    public function test_user_cannot_receive_another_users_code(): void
    {
        // Targeted coupon eligible ONLY for user A (dynamic metrics gate).
        $coupon = $this->makeTargetedCoupon(['type' => 'min_completed_orders', 'value' => 5], true);
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $this->setCompletedOrders($userA, 9);
        $this->setCompletedOrders($userB, 1);

        // Warm A's cached feed, then B's: per-user keys + version scoping.
        $itemA1 = $this->availableItemFor($userA, $coupon->id);
        $itemA2 = $this->availableItemFor($userA, $coupon->id);
        $this->assertNotNull($itemA1);
        $this->assertNotNull($itemA2);

        Sanctum::actingAs($userB);
        $bodyB = $this->getJson(self::AVAILABLE)->assertOk()->getContent();
        $this->assertNull(collect(json_decode($bodyB, true)['data']['data'] ?? [])->firstWhere('id', $coupon->id));
        $this->assertStringNotContainsString($coupon->code, $bodyB);

        // Assignment-only grant for B: visible in B's /mine with code,
        // absent from A's discovery and from A's /mine.
        $grant = $this->makePublicCoupon();
        CouponAssignment::create([
            'coupon_id' => $grant->id, 'user_id' => $userB->id,
            'max_uses' => 3, 'used' => 0,
        ]);

        Sanctum::actingAs($userB);
        $mineB = $this->getJson(self::MINE)->assertOk()->json();
        $this->assertSame($grant->code, collect($mineB['data']['assignments'])->firstWhere('coupon_id', $grant->id)['code']);

        Sanctum::actingAs($userA);
        $feedA = $this->getJson(self::AVAILABLE)->assertOk()->getContent();
        $this->assertStringNotContainsString($grant->code, $feedA);
        $mineA = $this->getJson(self::MINE)->assertOk()->json();
        $this->assertNull(collect($mineA['data']['assignments'])->firstWhere('coupon_id', $grant->id));
    }

    // -----------------------------------------------------------------
    // Test 6 — /mine behavior unchanged (owner codes exposed)
    // -----------------------------------------------------------------

    public function test_mine_still_exposes_owner_codes(): void
    {
        $coupon = $this->makePublicCoupon();
        $user = $this->makeUser();
        CouponAssignment::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id,
            'max_uses' => 3, 'used' => 0,
        ]);
        $claim = CouponClaim::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'claimed_at' => now(),
        ]);

        Sanctum::actingAs($user);
        $mine = $this->getJson(self::MINE)->assertOk()->json('data');

        $this->assertSame($coupon->code, collect($mine['assignments'])->firstWhere('coupon_id', $coupon->id)['code']);
        $this->assertSame($coupon->code, collect($mine['claims'])->firstWhere('id', $claim->id)['code']);
    }

    // -----------------------------------------------------------------
    // Test 7 — discovery shape unchanged
    // -----------------------------------------------------------------

    public function test_discovery_response_shape_is_stable(): void
    {
        $coupon = $this->makePublicCoupon();
        $user = $this->makeUser();

        $item = $this->availableItemFor($user, $coupon->id);

        $this->assertNotNull($item);
        foreach (['id', 'name', 'slug', 'image', 'visibility', 'claim_status', 'requires_claim', 'code', 'claim_id', 'expires_at', 'action'] as $key) {
            $this->assertArrayHasKey($key, $item);
        }
        $this->assertSame('public', $item['visibility']);
        $this->assertFalse($item['requires_claim']);
        $this->assertSame('not_required', $item['claim_status']);
        $this->assertSame('apply', $item['action']);
        $this->assertSame($coupon->code, $item['code']);
    }
}
