<?php

namespace App\Services\Coupon\Discovery;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Single home of Admin coupon-list filtering (GET /api/v1/coupons).
 *
 * Every filter AND-combines. Audience mapping mirrors
 * CouponAudienceResolver::composeType() boolean-for-boolean (provably
 * equivalent — see CouponAdminListFilterTest::audience_filter_matches_resolver):
 *   has_assignments ⇔ EXISTS coupon_assignments for the coupon
 *   has_targeting   ⇔ EXISTS coupon_targetings for the coupon
 *   is_public       ⇔ coupons.is_public flag
 *   type            ⇔ the same three-way composition (PUBLIC ignores the flag,
 *                     exactly like the resolver fallback).
 *
 * Reads raw request input defensively (works for the validated HTTP path and
 * the unvalidated GraphQL fetchCoupons path alike). No raw SQL with
 * interpolated input — all values are bound parameters.
 */
class AdminCouponFilter
{
    public const AUDIENCE_MAP = [
        'PUBLIC' => ['is_public' => null, 'has_assignments' => false, 'has_targeting' => false],
        'ASSIGNED' => ['is_public' => false, 'has_assignments' => true, 'has_targeting' => false],
        'TARGETED' => ['is_public' => false, 'has_assignments' => false, 'has_targeting' => true],
        'PUBLIC_AND_ASSIGNED' => ['is_public' => true, 'has_assignments' => true, 'has_targeting' => false],
        'PUBLIC_AND_TARGETED' => ['is_public' => true, 'has_assignments' => false, 'has_targeting' => true],
        'ASSIGNED_AND_TARGETED' => ['is_public' => false, 'has_assignments' => true, 'has_targeting' => true],
        'PUBLIC_AND_ASSIGNED_AND_TARGETED' => ['is_public' => true, 'has_assignments' => true, 'has_targeting' => true],
    ];

    public static function apply(Builder $query, Request $request): Builder
    {
        $in = $request->input();

        $present = fn (string $k): bool => array_key_exists($k, $in)
            && $in[$k] !== null && $in[$k] !== '';
        $bool = fn (string $k): bool => (bool) filter_var($in[$k], FILTER_VALIDATE_BOOLEAN);

        // Validity (exact scope mirror — see Coupon::scopeValid/scopeInvalid).
        // scopeInvalid carries top-level ORs, so it MUST be grouped to keep
        // AND-combination with sibling filters correct.
        if ($present('is_valid')) {
            $bool('is_valid')
                ? $query->valid()
                : $query->where(fn ($q) => $q->invalid());
        }

        // Explicit field ranges (null column values never match a bound).
        foreach ([
            ['start_date_from', 'start_date', '>='], ['start_date_to', 'start_date', '<='],
            ['end_date_from', 'end_date', '>='], ['end_date_to', 'end_date', '<='],
        ] as [$param, $column, $op]) {
            if ($present($param)) {
                $query->whereDate($column, $op, $in[$param]);
            }
        }
        foreach ([
            ['discount_min', 'discount', '>='], ['discount_max', 'discount', '<='],
            ['limiter_min', 'limiter', '>='], ['limiter_max', 'limiter', '<='],
            ['used_min', 'used', '>='], ['used_max', 'used', '<='],
        ] as [$param, $column, $op]) {
            if ($present($param)) {
                $query->where($column, $op, $in[$param]);
            }
        }

        // Overlap window (null bounds count as open-ended).
        if ($present('date_from')) {
            $from = $in['date_from'];
            $query->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $from));
        }
        if ($present('date_to')) {
            $to = $in['date_to'];
            $query->where(fn ($q) => $q->whereNull('start_date')->orWhereDate('start_date', '<=', $to));
        }

        // Discount type + cap ranges (cap filters skip null-cap rows).
        if ($present('discount_type')) {
            $query->where('discount_type', $in['discount_type']);
        }
        if ($present('max_discount_amount_min')) {
            $query->whereNotNull('max_discount_amount')
                ->where('max_discount_amount', '>=', $in['max_discount_amount_min']);
        }
        if ($present('max_discount_amount_max')) {
            $query->whereNotNull('max_discount_amount')
                ->where('max_discount_amount', '<=', $in['max_discount_amount_max']);
        }

        // Remaining = limiter - used; null limiter counts as +infinity
        // (unlimited passes any minimum, never a maximum).
        if ($present('remaining_min')) {
            $min = $in['remaining_min'];
            $query->where(fn ($q) => $q->whereNull('limiter')
                ->orWhereRaw('limiter - used >= ?', [$min]));
        }
        if ($present('remaining_max')) {
            $max = $in['remaining_max'];
            $query->whereNotNull('limiter')->whereRaw('limiter - used <= ?', [$max]);
        }

        // Expiry (narrow: past end_date only — distinct from is_valid).
        if ($present('expired')) {
            $bool('expired')
                ? $query->whereNotNull('end_date')->whereDate('end_date', '<', today())
                : $query->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', today()));
        }

        // Audience: composite type first, then capability flags (AND).
        if ($present('audience_type') && isset(self::AUDIENCE_MAP[$in['audience_type']])) {
            self::applyCapabilities($query, self::AUDIENCE_MAP[$in['audience_type']]);
        }
        if ($present('is_public')) {
            $query->where('coupons.is_public', $bool('is_public'));
        }
        if ($present('has_assignments')) {
            $bool('has_assignments')
                ? $query->whereHas('assignments')
                : $query->whereDoesntHave('assignments');
        }
        if ($present('has_targeting')) {
            $bool('has_targeting')
                ? $query->whereHas('targeting')
                : $query->whereDoesntHave('targeting');
        }

        // Targeting mode (eligibility only) + claim flag (lives on the row).
        if ($present('targeting_mode')) {
            $mode = $in['targeting_mode'];
            $query->whereHas('targeting', fn ($q) => $q->where('mode', $mode));
        }
        if ($present('require_claim')) {
            $query->whereHas('targeting', fn ($q) => $q->where('require_claim', $bool('require_claim')));
        }

        // Assignment owner (admin-authorized exists-subquery; no PII output).
        if ($present('assigned_user_id')) {
            $uid = (int) $in['assigned_user_id'];
            $query->whereHas('assignments', fn ($q) => $q->where('user_id', $uid));
        }

        return $query;
    }

    /**
     * @param array{is_public: ?bool, has_assignments: bool, has_targeting: bool} $caps
     */
    private static function applyCapabilities(Builder $query, array $caps): void
    {
        if ($caps['is_public'] !== null) {
            $query->where('coupons.is_public', $caps['is_public']);
        }
        $caps['has_assignments']
            ? $query->whereHas('assignments')
            : $query->whereDoesntHave('assignments');
        $caps['has_targeting']
            ? $query->whereHas('targeting')
            : $query->whereDoesntHave('targeting');
    }
}
