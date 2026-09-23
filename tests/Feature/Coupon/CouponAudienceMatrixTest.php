<?php

declare(strict_types=1);

namespace Tests\Feature\Coupon;

use App\Services\Coupon\Audience\CouponAudienceResolver;
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
 * Seven-state audience composition: is_public × assignments × targeting.
 *
 * is_public is an independent persisted flag (default false = legacy
 * behavior preserved). Assignments NEVER flip it. targeting.mode semantics
 * are untouched; audience.type is authoritative, legacy fields documented.
 */
class CouponAudienceMatrixTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'code' => 'MX-' . strtoupper(Str::random(6)),
            'slug' => 'mx-' . strtolower(Str::random(6)),
            'name' => 'Matrix coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'status' => true,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
        ], $overrides));
    }

    private function makeUser(string $type = 'user'): User
    {
        return User::factory()->create(['type' => $type]);
    }

    private function grant(Coupon $coupon, User $user): CouponAssignment
    {
        return CouponAssignment::create([
            'coupon_id' => $coupon->id, 'user_id' => $user->id, 'max_uses' => 2, 'used' => 0,
        ]);
    }

    private function target(Coupon $coupon, string $mode = 'dynamic'): CouponTargeting
    {
        return CouponTargeting::create(['coupon_id' => $coupon->id, 'mode' => $mode, 'rule_tree' => null]);
    }

    private function admin(array $permissions): User
    {
        foreach ($permissions as $name) {
            \Spatie\Permission\Models\Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
        $admin = User::factory()->create(['type' => 'admin']);
        $admin->givePermissionTo($permissions);

        return $admin;
    }

    /** @test — §15 Cases A–G */
    public function resolve_covers_all_seven_combinations()
    {
        $r = app(CouponAudienceResolver::class);

        // A: nothing configured → PUBLIC (existing rule preserved).
        $a = $this->makeCoupon();
        $this->assertSame(
            ['type' => 'PUBLIC', 'is_public' => false, 'has_assignments' => false, 'has_targeting' => false],
            $r->resolve($a)
        );

        // B: private + assignments → ASSIGNED.
        $b = $this->makeCoupon();
        $this->grant($b, $this->makeUser());
        $this->assertSame('ASSIGNED', $r->resolve($b->fresh())['type']);

        // C: private + targeting → TARGETED.
        $c = $this->makeCoupon();
        $this->target($c);
        $this->assertSame('TARGETED', $r->resolve($c->fresh())['type']);

        // D (CRITICAL): public + assignments → PUBLIC_AND_ASSIGNED.
        $d = $this->makeCoupon(['is_public' => true]);
        $this->grant($d, $this->makeUser());
        $this->assertSame(
            ['type' => 'PUBLIC_AND_ASSIGNED', 'is_public' => true, 'has_assignments' => true, 'has_targeting' => false],
            $r->resolve($d->fresh())
        );

        // E: public + targeting → PUBLIC_AND_TARGETED.
        $e = $this->makeCoupon(['is_public' => true]);
        $this->target($e);
        $this->assertSame('PUBLIC_AND_TARGETED', $r->resolve($e->fresh())['type']);

        // F: private + both → ASSIGNED_AND_TARGETED.
        $f = $this->makeCoupon();
        $this->grant($f, $this->makeUser());
        $this->target($f, 'assignment_and_dynamic');
        $this->assertSame('ASSIGNED_AND_TARGETED', $r->resolve($f->fresh())['type']);

        // G: everything → PUBLIC_AND_ASSIGNED_AND_TARGETED.
        $g = $this->makeCoupon(['is_public' => true]);
        $this->grant($g, $this->makeUser());
        $this->target($g, 'assignment_and_dynamic');
        $this->assertSame(
            ['type' => 'PUBLIC_AND_ASSIGNED_AND_TARGETED', 'is_public' => true, 'has_assignments' => true, 'has_targeting' => true],
            $r->resolve($g->fresh())
        );
    }

    /** @test */
    public function fresh_coupons_default_to_non_public_flag()
    {
        $this->assertFalse((bool) $this->makeCoupon()->is_public);
    }

    /** @test — §8 */
    public function admin_show_exposes_authoritative_audience_object()
    {
        Sanctum::actingAs($this->admin(['view-coupons']));

        $coupon = $this->makeCoupon(['is_public' => true]);
        $this->grant($coupon, $this->makeUser());

        $response = $this->getJson("/api/v1/coupons/{$coupon->id}");

        $response->assertOk()
            ->assertJsonPath('data.audience', [
                'type' => 'PUBLIC_AND_ASSIGNED',
                'is_public' => true,
                'has_assignments' => true,
                'has_targeting' => false,
            ])
            ->assertJsonPath('data.targeting', null)
            ->assertJsonPath('data.is_assigned', true)
            ->assertJsonPath('data.audience_type', 'PUBLIC_AND_ASSIGNED')
            ->assertJsonPath('data.assignments.0.max_uses', 2)
            ->assertJsonPath('data.assignments.0.used', 0)
            ->assertJsonPath('data.assignments.0.remaining', 2);
        // Shaped rows: no internal timestamps, no user PII object.
        $row = $response->json('data.assignments.0');
        $this->assertArrayNotHasKey('created_at', $row);
        $this->assertArrayNotHasKey('user', $row);
        $this->assertSame(
            ['id', 'coupon_id', 'user_id', 'max_uses', 'used', 'remaining', 'expires_at', 'assigned_at'],
            array_keys($row)
        );
    }

    /** @test — §16 index + §19 N+1 guard */
    public function admin_index_lists_audience_per_row_with_single_assignment_query()
    {
        Sanctum::actingAs($this->admin(['view-coupons']));

        foreach (range(1, 3) as $i) {
            $c = $this->makeCoupon(['is_public' => $i !== 3]);
            $this->grant($c, $this->makeUser());
        }

        DB::enableQueryLog();
        $response = $this->getJson('/api/v1/coupons?limit=50');
        $assignQueries = collect(DB::getQueryLog())
            ->filter(fn ($q) => str_contains($q['query'], 'coupon_assignments'))
            ->count();

        $response->assertOk();
        $rows = collect($response->json('data.data'));
        $this->assertSame(1, $assignQueries);
        $this->assertEquals(2, $rows->where('audience.type', 'PUBLIC_AND_ASSIGNED')->count());
        $this->assertEquals(1, $rows->where('audience.type', 'ASSIGNED')->count());
    }

    /** @test — §10 catalog keeps public+assigned, leaks nothing */
    public function public_assigned_coupon_stays_in_catalog_without_leaks()
    {
        $user = $this->makeUser();
        $coupon = $this->makeCoupon(['is_public' => true]);
        $this->grant($coupon, $user);

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/general/coupons?limit=50');

        $response->assertOk();
        $rows = collect($response->json('data'));
        $item = $rows->firstWhere('id', $coupon->id);
        $this->assertNotNull($item);
        $this->assertSame('public', $item['visibility']);
        foreach (['user_id', 'assignment_id', 'max_uses', 'used', 'expires_at'] as $leak) {
            $this->assertArrayNotHasKey($leak, $item);
        }
    }

    /** @test */
    public function private_assigned_coupon_stays_excluded()
    {
        $user = $this->makeUser();
        $coupon = $this->makeCoupon(); // is_public false
        $this->grant($coupon, $user);

        Sanctum::actingAs($user);
        $catalog = collect($this->getJson('/api/v1/general/coupons?limit=50')->json('data'));
        $this->assertNull($catalog->firstWhere('id', $coupon->id));

        $available = $this->getJson('/api/v1/general/coupons/available')->json('data.data');
        $this->assertNull(collect($available)->firstWhere('id', $coupon->id));
    }

    /** @test — §11 */
    public function mine_remains_owner_scoped()
    {
        $owner = $this->makeUser();
        $other = $this->makeUser();
        $coupon = $this->makeCoupon(['is_public' => true]);
        $this->grant($coupon, $owner);

        Sanctum::actingAs($other);
        $mine = $this->getJson('/api/v1/general/coupons/mine')->json('data');
        $this->assertEmpty($mine['assignments']);
        $this->assertEmpty($mine['claims']);

        Sanctum::actingAs($owner);
        $mine = $this->getJson('/api/v1/general/coupons/mine')->json('data');
        $this->assertCount(1, $mine['assignments']);
        $this->assertSame($coupon->id, $mine['assignments'][0]['coupon_id']);
        $this->assertSame($coupon->code, $mine['assignments'][0]['code']);
    }

    /** @test — §12 legacy compatibility */
    public function usage_info_keeps_legacy_coupon_type()
    {
        Sanctum::actingAs($this->admin(['view-coupons']));

        $coupon = $this->makeCoupon(['is_public' => true]);
        $this->grant($coupon, $this->makeUser());

        $response = $this->getJson("/api/v1/coupons/{$coupon->id}/usage-info");

        $response->assertOk()
            ->assertJsonPath('data.coupon_type', 'assigned')
            ->assertJsonPath('data.assignment_info.total_assignments', 1);
    }

    /** @test — §13 */
    public function assignment_creation_preserves_public_flag()
    {
        Sanctum::actingAs($this->admin(['view-coupons', 'create-coupon-assignment']));

        $coupon = $this->makeCoupon(['is_public' => true]);
        $user = $this->makeUser();

        $response = $this->postJson("/api/v1/coupons/{$coupon->id}/assignments", [
            'user_id' => $user->id, 'max_uses' => 2,
        ]);

        $response->assertCreated();
        $this->assertTrue($coupon->fresh()->is_public);
        $this->assertSame(
            'PUBLIC_AND_ASSIGNED',
            app(CouponAudienceResolver::class)->resolve($coupon->fresh())['type']
        );
    }

    /** @test — §14 targeting independence */
    public function targeting_creation_keeps_mode_and_public_dimensions()
    {
        Sanctum::actingAs($this->admin(['view-coupons', 'update-coupon']));

        $coupon = $this->makeCoupon(['is_public' => true]);

        $response = $this->putJson("/api/v1/coupons/{$coupon->id}/targeting", [
            'mode' => 'dynamic', 'require_claim' => false,
        ]);

        $response->assertOk()->assertJsonPath('data.mode', 'dynamic');
        $this->assertTrue($coupon->fresh()->is_public);
        $this->assertSame(
            'PUBLIC_AND_TARGETED',
            app(CouponAudienceResolver::class)->resolve($coupon->fresh())['type']
        );
    }

    /** @test — §20 cache refresh on is_public change */
    public function coupon_update_refreshes_cached_audience()
    {
        Sanctum::actingAs($this->admin(['view-coupons', 'update-coupon']));

        $coupon = $this->makeCoupon();

        $before = $this->getJson('/api/v1/coupons?limit=50')->json('data.data');
        $this->assertSame('PUBLIC', collect($before)->firstWhere('id', $coupon->id)['audience']['type']);

        $this->putJson("/api/v1/coupons/{$coupon->id}", ['is_public' => true])->assertOk();

        $after = $this->getJson('/api/v1/coupons?limit=50')->json('data.data');
        // No assignments/targeting and flag on → still PUBLIC type, flag visible.
        $row = collect($after)->firstWhere('id', $coupon->id);
        $this->assertTrue($row['audience']['is_public']);
    }

    /** @test — §18 authorization */
    public function admin_show_requires_permission()
    {
        Sanctum::actingAs($this->makeUser());

        $coupon = $this->makeCoupon(['is_public' => true]);

        $this->getJson("/api/v1/coupons/{$coupon->id}")->assertForbidden();
    }
}
