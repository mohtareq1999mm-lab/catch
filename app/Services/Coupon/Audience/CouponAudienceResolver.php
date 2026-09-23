<?php

namespace App\Services\Coupon\Audience;

use App\Enums\UserType;
use Illuminate\Database\Eloquent\Builder;
use Marvel\Database\Models\Coupon;

/**
 * Authoritative coupon audience classification (distribution/discovery).
 *
 * Three INDEPENDENT capabilities (single source of truth — Resources render,
 * they never decide):
 * - is_public:        persisted `coupons.is_public` flag (public discoverability).
 *                     NEVER derived from assignments: an assignment must not
 *                     remove publicity.
 * - has_assignments:  one or more coupon_assignments rows exist.
 * - has_targeting:    a coupon_targetings row exists (any mode).
 *
 * Derived composite type (7 combinations):
 *   PUBLIC | ASSIGNED | TARGETED |
 *   PUBLIC_AND_ASSIGNED | PUBLIC_AND_TARGETED |
 *   ASSIGNED_AND_TARGETED | PUBLIC_AND_ASSIGNED_AND_TARGETED
 * A coupon with no capabilities at all stays PUBLIC (existing business rule:
 * unconfigured coupons are publicly discoverable).
 *
 * Legacy notes (frozen, documented — NOT redefined here):
 * - Coupon::isPublic() ("no assignment rows") feeds usage-info coupon_type.
 * - targeting.mode (assignment|dynamic|and|or) evaluates eligibility only;
 *   it never carries public visibility.
 */
class CouponAudienceResolver
{
    /**
     * Authoritative audience resolution.
     *
     * @return array{type: string, is_public: bool, has_assignments: bool, has_targeting: bool}
     */
    public function resolve(Coupon $coupon): array
    {
        $isPublic = (bool) ($coupon->getAttribute('is_public') ?? false);
        $assigned = $coupon->relationLoaded('assignments')
            ? $coupon->assignments->isNotEmpty()
            : $coupon->assignments()->exists();
        $targeted = $coupon->relationLoaded('targeting')
            ? $coupon->targeting !== null
            : $coupon->targeting()->exists();

        return [
            'type' => self::composeType($isPublic, $assigned, $targeted),
            'is_public' => $isPublic,
            'has_assignments' => $assigned,
            'has_targeting' => $targeted,
        ];
    }

    /**
     * Deterministic composite label from the three capabilities.
     */
    public static function composeType(bool $isPublic, bool $assigned, bool $targeted): string
    {
        return match (true) {
            $assigned && $targeted => $isPublic
                ? 'PUBLIC_AND_ASSIGNED_AND_TARGETED'
                : 'ASSIGNED_AND_TARGETED',
            $assigned => $isPublic ? 'PUBLIC_AND_ASSIGNED' : 'ASSIGNED',
            $targeted => $isPublic ? 'PUBLIC_AND_TARGETED' : 'TARGETED',
            default => 'PUBLIC',
        };
    }

    /**
     * @return array{public: bool, assigned: bool, targeted: bool}
     *
     * Legacy descriptive sources (kept for backward compatibility).
     * NOTE: `public` here now means publicly discoverable = explicit flag
     * OR the legacy no-configuration state (matches resolve() type PUBLIC).
     */
    public function sources(Coupon $coupon): array
    {
        $resolved = $this->resolve($coupon);

        return [
            'public' => $resolved['is_public'] || $resolved['type'] === 'PUBLIC',
            'assigned' => $resolved['has_assignments'],
            'targeted' => $resolved['has_targeting'],
        ];
    }

    /**
     * Deterministic combination label, e.g. "public", "assigned+targeted".
     */
    public function describe(Coupon $coupon): string
    {
        $sources = $this->sources($coupon);

        $active = array_keys(array_filter($sources));

        return $active === [] ? 'none' : implode('+', $active);
    }

    /**
     * API-facing audience state (uppercase contract, 7 composite values).
     * Computed only — never persisted; isPublic() semantics untouched.
     * Legacy values (PUBLIC|ASSIGNED|TARGETED|ASSIGNED_AND_TARGETED) keep
     * their exact meaning for rows without the explicit public flag.
     */
    public function audienceType(Coupon $coupon): string
    {
        return $this->resolve($coupon)['type'];
    }

    /**
     * Audience union primitive (§6): ORs this coupon's assigned customers
     * into a candidate user query (indexed subquery, no memory load).
     * Single home of the "assigned audience" rule — fan-out calls this
     * instead of re-deriving assignment membership inline.
     */
    public function applyAssignedUnion(Builder $users, int $couponId): void
    {
        $users->orWhere(function ($q) use ($couponId) {
            $q->where('users.type', UserType::USER->value)
                ->whereIn('users.id', function ($sub) use ($couponId) {
                    $sub->select('user_id')
                        ->from('coupon_assignments')
                        ->where('coupon_id', $couponId);
                });
        });
    }
}
