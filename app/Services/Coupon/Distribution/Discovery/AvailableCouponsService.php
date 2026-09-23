<?php

namespace App\Services\Coupon\Distribution\Discovery;

use App\Enums\CouponClaimStatus;
use App\Services\Coupon\Eligibility\EligibilityEngine;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\User;

/**
 * Personalized coupon discovery: valid + targeting + Engine-eligible,
 * paginated BEFORE evaluation so each request runs a bounded number of
 * Engine calls (never all-coupons × Engine). Claim/apply/checkout remain
 * authoritative — this endpoint is advisory and never grants anything.
 *
 * Cache is scoped per (user, page, targeting-version, limit): 60s TTL.
 */
class AvailableCouponsService
{
    public function __construct(
        private readonly EligibilityEngine $engine,
    ) {}

    /**
     * @return array{data: list<array<string,mixed>>, meta: array<string,mixed>}
     */
    public function forUser(User $user, int $page = 1, int $limit = 15): array
    {
        $limit = max(1, min($limit, (int) config('coupon-distribution.available_max_limit', 50)));
        $page = max(1, $page);

        // Targeting-version scoping: any targeting edit busts the cache.
        $version = (string) \Marvel\Database\Models\CouponTargeting::query()->max('updated_at');
        $cacheKey = implode(':', ['coupon:available', $user->getKey(), $page, $limit, md5($version ?? 'none')]);

        return Cache::remember($cacheKey, (int) config('coupon-distribution.available_cache_ttl', 60), function () use ($user, $page, $limit) {
            return $this->computeForUser($user, $page, $limit);
        });
    }

    /**
     * @return array{data: list<array<string,mixed>>, meta: array<string,mixed>}
     */
    private function computeForUser(User $user, int $page, int $limit): array
    {
        /** @var LengthAwarePaginator $paginator */
        $paginator = Coupon::query()
            ->valid()
            ->whereHas('targeting')
            ->with(['targeting'])
            ->orderByDesc('coupons.id')
            ->paginate($limit, ['coupons.*'], 'page', $page);

        $couponIds = $paginator->getCollection()->map->getKey()->all();

        $claims = CouponClaim::query()
            ->where('user_id', $user->getKey())
            ->whereIn('coupon_id', $couponIds)
            ->get()
            ->keyBy('coupon_id');

        $items = [];

        foreach ($paginator->getCollection() as $coupon) {
            if (! $this->engine->evaluate($coupon, $user)->isEligible) {
                continue;
            }

            $claim = $claims->get($coupon->getKey());
            $items[] = $this->present($coupon, $claim);
        }

        return [
            // meta counts the ENGINE-ELIGIBLE items on this page, never the
            // pre-filter paginator total (which would leak hidden-campaign
            // counts to ineligible users).
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => count($items),
                'has_more_pages' => $paginator->hasMorePages(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function present(Coupon $coupon, ?CouponClaim $claim): array
    {
        $targeting = $coupon->targeting;
        $requiresClaim = (bool) ($targeting?->require_claim);

        $status = $claim?->status;
        $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;

        $activeClaim = $claim !== null
            && $statusValue === CouponClaimStatus::ACTIVE->value
            && ($claim->expires_at === null || $claim->expires_at->isFuture());

        if ($claim !== null && $statusValue === CouponClaimStatus::REDEEMED->value) {
            $claimStatus = 'redeemed';
            $action = 'none';
        } elseif ($activeClaim) {
            $claimStatus = 'claimed';
            $action = 'apply';
        } elseif (! $requiresClaim) {
            $claimStatus = 'not_required';
            $action = 'apply';
        } else {
            $claimStatus = 'claimable';
            $action = 'claim';
        }

        return [
            'id' => $coupon->getKey(),
            'name' => $coupon->name,
            'slug' => $coupon->slug,
            'image' => $coupon->image,
            'claim_status' => $claimStatus,
            'requires_claim' => $requiresClaim,
            'claim_id' => $claim?->getKey(),
            'expires_at' => $coupon->end_date?->toIso8601String(),
            'action' => $action,
        ];
    }
}
