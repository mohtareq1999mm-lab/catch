<?php

namespace App\Services\Coupon\Distribution\Discovery;

use App\Enums\CouponClaimStatus;
use App\Services\Coupon\Discovery\CouponDiscoveryPolicy;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponAssignment;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\User;

/**
 * Personalized coupon discovery: valid + Engine-eligible, paginated BEFORE
 * evaluation so each request runs a bounded number of Engine calls (never
 * all-coupons × Engine). Claim/apply/checkout remain authoritative — this
 * endpoint is advisory and never grants anything.
 *
 * Discovery categories (single deterministic classification per coupon):
 * - targeted: has a targeting row and the user is Engine-eligible.
 * - public: no targeting row and no assignments (canonical public =
 *   Coupon::isPublic()). The Engine trivially passes these (no_targeting).
 * Assignment-only coupons (assignments but no targeting) are excluded here;
 * their owners discover them via "My Coupons", which exposes codes.
 *
 * Cache is scoped per (user, page, targeting-version, limit): 60s TTL.
 */
class AvailableCouponsService
{
    public function __construct(
        private readonly CouponDiscoveryPolicy $policy,
    ) {}

    /**
     * @return array{data: list<array<string,mixed>>, meta: array<string,mixed>}
     */
    public function forUser(User $user, int $page = 1, int $limit = 15): array
    {
        $limit = max(1, min($limit, (int) config('coupon-distribution.available_max_limit', 50)));
        $page = max(1, $page);

        // Version scoping: any targeting, coupon, or assignment edit busts
        // the cache (assignments flip public ↔ assigned classification).
        $version = implode('|', [
            (string) \Marvel\Database\Models\CouponTargeting::query()->max('updated_at'),
            (string) Coupon::query()->max('updated_at'),
            (string) CouponAssignment::query()->max('updated_at'),
        ]);
        $cacheKey = implode(':', ['coupon:available', $user->getKey(), $page, $limit, md5($version)]);

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
            ->where(function ($q) {
                // Targeted candidates (any targeting row) plus public
                // candidates (no assignments). Assignment-only coupons are
                // excluded — see class docblock.
                $q->whereHas('targeting')->orWhereDoesntHave('assignments');
            })
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
            // Canonical discovery decision (visibility, eligibility, claim
            // requirement, code exposure) — shared with every customer
            // listing endpoint. Ineligible targeted coupons are skipped.
            $decision = $this->policy->decide($coupon, $user);

            if (! $decision['include']) {
                continue;
            }

            $claim = $claims->get($coupon->getKey());
            $items[] = $this->present($coupon, $claim, $decision);
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
     * @param array{visibility: 'public'|'targeted'|null, requires_claim: bool, eligible: bool, can_expose_code: bool, include: bool} $decision
     * @return array<string, mixed>
     */
    private function present(Coupon $coupon, ?CouponClaim $claim, array $decision): array
    {
        $requiresClaim = $decision['requires_claim'];

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
            'visibility' => $decision['visibility'],
            'claim_status' => $claimStatus,
            'requires_claim' => $requiresClaim,
            'code' => $decision['can_expose_code'] ? $coupon->code : null,
            'claim_id' => $claim?->getKey(),
            'expires_at' => $coupon->end_date?->toIso8601String(),
            'action' => $action,
        ];
    }
}
