<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use App\Services\Coupon\Audience\CouponAudienceResolver;
use App\Services\Coupon\Discovery\AdminCouponFilter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponTargeting;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * Admin coupon list filtering & sorting contract.
 *
 * Existing params preserved; new filters AND-combine; audience mapping is
 * provably equivalent to the authoritative resolver; malformed input → 422.
 */
class CouponAdminListFilterTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'F-' . strtoupper(Str::random(6)),
            'slug' => 'f-' . strtolower(Str::random(6)),
            'name' => 'Filter coupon',
            'discount_type' => 'fixed_rate',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay()->toDateString(),
            'end_date' => now()->addMonth()->toDateString(),
        ], $overrides));
    }

    private function grant(Coupon $c, User $u, int $maxUses = 1): void
    {
        CouponAssignment::create([
            'coupon_id' => $c->id, 'user_id' => $u->id, 'max_uses' => $maxUses, 'used' => 0,
        ]);
    }

    private function target(Coupon $c, string $mode = 'dynamic', array $extra = []): void
    {
        CouponTargeting::create(array_merge(
            ['coupon_id' => $c->id, 'mode' => $mode, 'rule_tree' => null],
            $extra
        ));
    }

    private function admin(): User
    {
        \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'view-coupons', 'guard_name' => 'api']);
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->givePermissionTo('view-coupons');

        return $admin;
    }

    private function ids(string $url): array
    {
        return collect($this->getJson($url)->json('data.data'))->pluck('id')->all();
    }

    /** @test */
    public function existing_search_still_works()
    {
        Sanctum::actingAs($this->admin());
        $c = $this->makeCoupon(['code' => 'SUMMERX1', 'name' => 'Summer Blast']);

        $this->assertContains($c->id, $this->ids('/api/v1/coupons?search=SUMMERX1'));
        $this->assertContains($c->id, $this->ids('/api/v1/coupons?search=Blast'));
        $this->assertNotContains($c->id, $this->ids('/api/v1/coupons?search=ZZZ-NOMATCH'));
    }

    /** @test */
    public function active_inactive_preserved()
    {
        Sanctum::actingAs($this->admin());
        $on = $this->makeCoupon(['status' => true]);
        $off = $this->makeCoupon(['status' => false]);

        $active = $this->ids('/api/v1/coupons?active=1&limit=50');
        $this->assertContains($on->id, $active);
        $this->assertNotContains($off->id, $active);

        $inactive = $this->ids('/api/v1/coupons?inactive=1&limit=50');
        $this->assertContains($off->id, $inactive);
        $this->assertNotContains($on->id, $inactive);
    }

    /** @test */
    public function is_valid_matches_response_semantics()
    {
        Sanctum::actingAs($this->admin());
        $valid = $this->makeCoupon();
        $disabled = $this->makeCoupon(['status' => false]);
        $expired = $this->makeCoupon(['end_date' => now()->subDay()->toDateString()]);
        $exhausted = $this->makeCoupon(['limiter' => 5, 'used' => 5]);

        $good = $this->ids('/api/v1/coupons?is_valid=true&limit=50');
        $this->assertContains($valid->id, $good);
        foreach ([$disabled, $expired, $exhausted] as $bad) {
            $this->assertNotContains($bad->id, $good);
        }

        $badList = $this->ids('/api/v1/coupons?is_valid=false&limit=50');
        foreach ([$disabled, $expired, $exhausted] as $bad) {
            $this->assertContains($bad->id, $badList);
        }
        $this->assertNotContains($valid->id, $badList);
    }

    /** @test — AND-precedence proof (grouped invalid + grouped search) */
    public function invalid_filter_combines_with_search_using_and()
    {
        Sanctum::actingAs($this->admin());
        $bad = $this->makeCoupon(['code' => 'BROKEN1', 'status' => false]);
        $good = $this->makeCoupon(['code' => 'BROKEN2', 'status' => true]);

        // search alone finds both (grouped OR inside AND scope is transparent).
        $this->assertContains($bad->id, $this->ids('/api/v1/coupons?search=BROKEN'));
        // is_valid=false narrows to the invalid one only.
        $this->assertEqualsCanonicalizing(
            [$bad->id],
            $this->ids('/api/v1/coupons?search=BROKEN&is_valid=false')
        );
        // is_valid=true narrows to the valid one only (code match cannot leak).
        $this->assertEqualsCanonicalizing(
            [$good->id],
            $this->ids('/api/v1/coupons?search=BROKEN&is_valid=true')
        );
    }

    /** @test */
    public function date_range_and_overlap_filters()
    {
        Sanctum::actingAs($this->admin());
        $sept = $this->makeCoupon([
            'start_date' => '2026-09-01', 'end_date' => '2026-09-30',
        ]);
        $oct = $this->makeCoupon([
            'start_date' => '2026-10-01', 'end_date' => '2026-10-31',
        ]);

        $this->assertContains($sept->id, $this->ids('/api/v1/coupons?start_date_from=2026-09-01&start_date_to=2026-09-30&limit=50'));
        $this->assertNotContains($oct->id, $this->ids('/api/v1/coupons?start_date_from=2026-09-01&start_date_to=2026-09-30&limit=50'));

        // Overlap: coupon active during requested window (null bounds open).
        $this->assertContains($sept->id, $this->ids('/api/v1/coupons?date_from=2026-09-15&date_to=2026-09-20&limit=50'));
        $this->assertNotContains($oct->id, $this->ids('/api/v1/coupons?date_from=2026-09-15&date_to=2026-09-20&limit=50'));

        // Malformed → 422.
        $this->getJson('/api/v1/coupons?start_date_from=not-a-date')->assertStatus(422);
        $this->getJson('/api/v1/coupons?date_from=2026-09-20&date_to=2026-09-15')->assertStatus(422);
    }

    /** @test */
    public function discount_filters_use_persisted_values()
    {
        Sanctum::actingAs($this->admin());
        $cheap = $this->makeCoupon(['discount_type' => 'fixed_rate', 'discount' => 5]);
        $pct = $this->makeCoupon(['discount_type' => 'percentage', 'discount' => 50, 'max_discount_amount' => 200]);

        $this->assertContains($pct->id, $this->ids('/api/v1/coupons?discount_type=percentage&limit=50'));
        $this->assertNotContains($cheap->id, $this->ids('/api/v1/coupons?discount_type=percentage&limit=50'));

        $mid = $this->ids('/api/v1/coupons?discount_min=10&discount_max=60&limit=50');
        $this->assertContains($pct->id, $mid);
        $this->assertNotContains($cheap->id, $mid);

        $capped = $this->ids('/api/v1/coupons?max_discount_amount_min=100&limit=50');
        $this->assertContains($pct->id, $capped);
        $this->assertNotContains($cheap->id, $capped);

        $this->getJson('/api/v1/coupons?discount_type=fixed')->assertStatus(422);
        $this->getJson('/api/v1/coupons?discount_min=60&discount_max=10')->assertStatus(422);
    }

    /** @test */
    public function limiter_used_remaining_ranges()
    {
        Sanctum::actingAs($this->admin());
        $limited = $this->makeCoupon(['limiter' => 100, 'used' => 90]); // remaining 10
        $unlimited = $this->makeCoupon(['limiter' => null, 'used' => 7]);

        $this->assertContains($limited->id, $this->ids('/api/v1/coupons?limiter_min=50&limit=50'));
        $this->assertNotContains($unlimited->id, $this->ids('/api/v1/coupons?limiter_min=50&limit=50'));

        $this->assertContains($limited->id, $this->ids('/api/v1/coupons?used_min=5&limit=50'));

        // Unlimited counts as +infinity: passes minimums, never a maximum.
        $this->assertContains($unlimited->id, $this->ids('/api/v1/coupons?remaining_min=1000000&limit=50'));
        $this->assertNotContains($unlimited->id, $this->ids('/api/v1/coupons?remaining_max=1000000&limit=50'));
        $this->assertContains($limited->id, $this->ids('/api/v1/coupons?remaining_max=10&limit=50'));
        $this->assertNotContains($limited->id, $this->ids('/api/v1/coupons?remaining_min=11&limit=50'));
    }

    /** @test */
    public function expired_filter_is_narrow_end_date_only()
    {
        Sanctum::actingAs($this->admin());
        $expired = $this->makeCoupon(['end_date' => now()->subDay()->toDateString()]);
        $live = $this->makeCoupon();

        $this->assertContains($expired->id, $this->ids('/api/v1/coupons?expired=true&limit=50'));
        $this->assertNotContains($live->id, $this->ids('/api/v1/coupons?expired=true&limit=50'));
        $this->assertContains($live->id, $this->ids('/api/v1/coupons?expired=false&limit=50'));
        $this->assertNotContains($expired->id, $this->ids('/api/v1/coupons?expired=false&limit=50'));
    }

    /** @test — all 7 audience states + resolver equivalence */
    public function audience_type_filter_matches_resolver_for_all_states()
    {
        Sanctum::actingAs($this->admin());
        $resolver = app(CouponAudienceResolver::class);
        $user = User::factory()->create();

        $matrix = [
            'PUBLIC' => $this->makeCoupon(),
            'ASSIGNED' => tap($this->makeCoupon(), fn ($c) => $this->grant($c, $user)),
            'TARGETED' => tap($this->makeCoupon(), fn ($c) => $this->target($c)),
            'PUBLIC_AND_ASSIGNED' => tap($this->makeCoupon(['is_public' => true]), fn ($c) => $this->grant($c, $user)),
            'PUBLIC_AND_TARGETED' => tap($this->makeCoupon(['is_public' => true]), fn ($c) => $this->target($c)),
            'ASSIGNED_AND_TARGETED' => tap($this->makeCoupon(), function ($c) use ($user) {
                $this->grant($c, $user);
                $this->target($c, 'assignment_and_dynamic');
            }),
            'PUBLIC_AND_ASSIGNED_AND_TARGETED' => tap($this->makeCoupon(['is_public' => true]), function ($c) use ($user) {
                $this->grant($c, $user);
                $this->target($c, 'assignment_and_dynamic');
            }),
        ];

        foreach ($matrix as $type => $coupon) {
            // Resolver agrees with the fixture intent.
            $this->assertSame($type, $resolver->resolve($coupon->fresh())['type'], $type);
            // SQL filter returns exactly the matching coupon(s).
            $found = $this->ids('/api/v1/coupons?audience_type=' . $type . '&limit=50');
            $this->assertContains($coupon->id, $found, $type);
            foreach ($matrix as $otherType => $other) {
                if ($otherType !== $type) {
                    $this->assertNotContains($other->id, $found, "{$type} excludes {$otherType}");
                }
            }
        }

        $this->getJson('/api/v1/coupons?audience_type=PUBLIC_OR_ASSIGNED')->assertStatus(422);
    }

    /** @test */
    public function capability_flags_and_combine_predictably()
    {
        Sanctum::actingAs($this->admin());
        $user = User::factory()->create();

        $pubAssign = $this->makeCoupon(['is_public' => true]);
        $this->grant($pubAssign, $user);
        $privAssign = $this->makeCoupon();
        $this->grant($privAssign, $user);
        $full = $this->makeCoupon(['is_public' => true]);
        $this->grant($full, $user);
        $this->target($full, 'dynamic');
        $plain = $this->makeCoupon();

        $both = $this->ids('/api/v1/coupons?is_public=true&has_assignments=true&limit=50');
        $this->assertContains($pubAssign->id, $both);
        $this->assertContains($full->id, $both);
        $this->assertNotContains($privAssign->id, $both);
        $this->assertNotContains($plain->id, $both);

        $noTarget = $this->ids('/api/v1/coupons?has_targeting=false&limit=50');
        $this->assertContains($pubAssign->id, $noTarget);
        $this->assertNotContains($full->id, $noTarget);

        $this->getJson('/api/v1/coupons?is_public=yes')->assertStatus(422);
    }

    /** @test */
    public function targeting_mode_filter_stays_eligibility_only()
    {
        Sanctum::actingAs($this->admin());
        $dyn = $this->makeCoupon();
        $this->target($dyn, 'dynamic');
        $both = $this->makeCoupon();
        $this->target($both, 'assignment_and_dynamic');
        $plain = $this->makeCoupon();

        $found = $this->ids('/api/v1/coupons?targeting_mode=dynamic&limit=50');
        $this->assertContains($dyn->id, $found);
        $this->assertNotContains($both->id, $found);
        $this->assertNotContains($plain->id, $found);

        $this->getJson('/api/v1/coupons?targeting_mode=public_and_assigned')->assertStatus(422);
    }

    /** @test */
    public function assigned_user_and_require_claim_filters()
    {
        Sanctum::actingAs($this->admin());

        $user = User::factory()->create();
        $other = User::factory()->create();
        $granted = $this->makeCoupon();
        $this->grant($granted, $user);
        $claimed = $this->makeCoupon();
        $this->target($claimed, 'dynamic', ['require_claim' => true]);
        $noClaim = $this->makeCoupon();
        $this->target($noClaim, 'dynamic', ['require_claim' => false]);

        $mine = $this->ids('/api/v1/coupons?assigned_user_id=' . $user->id . '&limit=50');
        $this->assertContains($granted->id, $mine);
        $this->assertNotContains($claimed->id, $mine);

        $empty = $this->ids('/api/v1/coupons?assigned_user_id=' . $other->id . '&limit=50');
        $this->assertNotContains($granted->id, $empty);

        $req = $this->ids('/api/v1/coupons?require_claim=true&limit=50');
        $this->assertContains($claimed->id, $req);
        $this->assertNotContains($noClaim->id, $req);
        $this->assertNotContains($granted->id, $req);
    }

    /** @test */
    public function sorting_pagination_and_combined_filters()
    {
        Sanctum::actingAs($this->admin());
        $lo = $this->makeCoupon(['discount' => 5]);
        $hi = $this->makeCoupon(['discount' => 90, 'discount_type' => 'percentage']);

        $desc = $this->ids('/api/v1/coupons?order=discount&sortedBy=desc&limit=50');
        $this->assertLessThan(array_search($lo->id, $desc), array_search($hi->id, $desc));

        // Combined §22-style query.
        $combo = $this->getJson(
            '/api/v1/coupons?active=1&discount_type=percentage&discount_min=10&discount_max=95&order=created_at&sortedBy=desc&limit=50'
        );
        $combo->assertOk();
        $ids = collect($combo->json('data.data'))->pluck('id')->all();
        $this->assertContains($hi->id, $ids);
        $this->assertNotContains($lo->id, $ids);

        // Pagination clamp + malformed sort rejected.
        $this->assertCount(1, $this->getJson('/api/v1/coupons?limit=1')->json('data.data'));
        $this->getJson('/api/v1/coupons?limit=0')->assertStatus(422);
        $this->getJson('/api/v1/coupons?order=hacked')->assertStatus(422);
        $this->getJson('/api/v1/coupons?sortedBy=sideways')->assertStatus(422);
    }

    /** @test */
    public function authorization_and_cache_separation()
    {
        // Guest → 401.
        $this->getJson('/api/v1/coupons')->assertUnauthorized();

        // User without permission → 403.
        Sanctum::actingAs(User::factory()->create());
        $this->getJson('/api/v1/coupons')->assertForbidden();

        // Distinct filters → distinct cache entries.
        Sanctum::actingAs($this->admin());
        $pct = $this->makeCoupon(['discount_type' => 'percentage', 'discount' => 42]);
        $fix = $this->makeCoupon(['discount_type' => 'fixed_rate', 'discount' => 42]);

        $a = $this->ids('/api/v1/coupons?discount_type=percentage&limit=50');
        $b = $this->ids('/api/v1/coupons?discount_type=fixed_rate&limit=50');
        $this->assertContains($pct->id, $a);
        $this->assertNotContains($fix->id, $a);
        $this->assertContains($fix->id, $b);
        $this->assertNotContains($pct->id, $b);

        // Mutation refreshes the filtered entry (invalidation intact).
        $pct->update(['discount_type' => 'fixed_rate']);
        $a2 = $this->ids('/api/v1/coupons?discount_type=percentage&limit=50');
        $this->assertNotContains($pct->id, $a2);
    }

    /** @test — §20 bounded query complexity */
    public function index_query_count_stays_bounded()
    {
        Sanctum::actingAs($this->admin());
        foreach (range(1, 5) as $i) {
            $c = $this->makeCoupon(['discount' => $i * 10]);
            $this->grant($c, User::factory()->create());
            $this->target($c, 'dynamic');
        }

        DB::enableQueryLog();
        $this->getJson('/api/v1/coupons?audience_type=PUBLIC_AND_ASSIGNED_AND_TARGETED&is_valid=true&limit=50')
            ->assertOk();
        $log = collect(DB::getQueryLog());

        $this->assertSame(1, $log->filter(fn ($q) => str_contains($q['query'], 'coupon_assignments'))->count());
        $this->assertSame(1, $log->filter(fn ($q) => str_contains($q['query'], 'coupon_targetings'))->count());
        $this->assertLessThan(25, $log->count());
    }

    /** @test — mapping table provably mirrors the resolver */
    public function audience_map_covers_all_resolver_types()
    {
        $this->assertSame(
            ['PUBLIC', 'ASSIGNED', 'TARGETED', 'PUBLIC_AND_ASSIGNED', 'PUBLIC_AND_TARGETED', 'ASSIGNED_AND_TARGETED', 'PUBLIC_AND_ASSIGNED_AND_TARGETED'],
            array_keys(AdminCouponFilter::AUDIENCE_MAP)
        );
        foreach (AdminCouponFilter::AUDIENCE_MAP as $type => $caps) {
            $this->assertSame(
                $type,
                CouponAudienceResolver::composeType(
                    (bool) $caps['is_public'],
                    $caps['has_assignments'],
                    $caps['has_targeting']
                ),
                $type
            );
        }
    }
}
