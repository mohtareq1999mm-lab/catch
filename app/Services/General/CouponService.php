<?php

namespace App\Services\General;

use Illuminate\Support\Facades\DB;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponClaim;
use Marvel\Database\Models\User;
use App\Services\Coupon\CouponOrchestrator;
use App\Services\Coupon\CouponCalculator;
use App\Services\Coupon\Discovery\CouponDiscoveryPolicy;

class CouponService
{
    /**
     * Customer coupon catalog with the canonical discovery policy applied.
     *
     * VISIBILITY ≠ ELIGIBILITY: public + targeted coupons are all listed
     * subject to normal validity; eligibility only shapes code/action.
     * Private assignment-only coupons (assignments, no targeting, public
     * flag off) are excluded — personal grants belong to My Coupons.
     * Publicly discoverable coupons stay listed even with assignments.
     * Guests see public + targeted rows with codes hidden. Each model carries `discoveryDecision`
     * (+`userClaim` when signed in) for the resource layer. Search/date/id
     * filters and ordering are preserved; result stays a plain limited
     * Collection (no paginator).
     */
    public function getCoupons($request, ?User $user = null)
    {
        $name = $request->get("search", false);
        $limit = min(100, max(1, (int) $request->get('limit', 10)));
        $start_date = $request->query('start_date');
        $end_date   = $request->query('end_date');
        $couponsId = $request->query('couponsId');
        $order = $request->query('order', 'desc');
        $coupons = Coupon::valid()->when($name, function ($query) use ($name) {
            $query->search('name', $name, app()->getLocale());
        })->when($start_date, function ($query) use ($start_date) {
                $query->where('created_at', '>=', $start_date);
            })
            ->when($end_date, function ($query) use ($end_date) {
                $query->where('created_at', '<=', $end_date);
            });

        if (!empty($couponsId)) {
            $ids = is_array($couponsId) ? $couponsId : explode(',', $couponsId);
            $ids = array_filter($ids, 'is_numeric');
            if (!empty($ids)) {
                $coupons->whereIn('id', $ids);
            }
        }

        $models = $coupons
            ->where(function ($query) {
                // Catalog = public + targeted. Assignment-only coupons
                // (assignments without targeting AND without the public
                // flag) are excluded for everyone: personal grants stay
                // private to assignees via My Coupons. Publicly
                // discoverable coupons stay listed even with assignments.
                $query->whereHas('targeting')->orWhereDoesntHave('assignments')
                    ->orWhere('is_public', true);
            })
            ->with(['targeting'])
            ->orderBy('id', $order)->limit($limit)->get();

        // One batched claim lookup per listing (mirrors the personalized
        // surface): per-coupon claim state drives claim/action derivation.
        if ($user) {
            $claims = CouponClaim::query()
                ->where('user_id', $user->getKey())
                ->whereIn('coupon_id', $models->map->getKey()->all())
                ->get()
                ->keyBy('coupon_id');
        } else {
            $claims = collect();
        }

        $policy = app(CouponDiscoveryPolicy::class);

        return $models
            ->map(function ($coupon) use ($policy, $user, $claims) {
                $coupon->setRelation(
                    'discoveryDecision',
                    $policy->decide($coupon, $user, $claims->get($coupon->getKey()))
                );
                $coupon->setRelation('userClaim', $claims->get($coupon->getKey()));

                return $coupon;
            })
            ->filter(fn ($coupon) => $coupon->getRelation('discoveryDecision')['visibility'] !== 'assignment-only')
            ->values();
    }

    public function calcPrice(Coupon $coupon, $price)
    {
        $result = CouponCalculator::calculate($coupon, (float) $price);
        return $result['finalPrice'];
    }

    public function calcPriceByCode(string $code, $price): ?float
    {
        // CP-09: canonical lookup (case-insensitive, trimmed).
        $coupon = Coupon::byCode($code)->first();

        if (!$coupon) {
            return null;
        }

        $result = CouponCalculator::calculate($coupon, (float) $price);
        return $result['finalPrice'];
    }

    public function findByCode(string $code): ?Coupon
    {
        // CP-09: canonical lookup (case-insensitive, trimmed).
        return Coupon::byCode($code)->first();
    }

    public function addCouponToCart($code, array $context = [])
    {
        return DB::transaction(function () use ($code, $context) {
            $user = auth()->user();

            if (!$user || !$user->cart) {
                return null;
            }

            $cart = $user->cart;

            // S1: canonical compare so case/whitespace variants hit the
            // early return instead of re-validating.
            if (\App\Support\CouponCode::normalize($cart->coupon) === \App\Support\CouponCode::normalize($code)) {
                return ['already_applied' => true];
            }

            $validation = CouponOrchestrator::validateByCode($code, $user, $cart->items, $context);

            if (!$validation['valid']) {
                // P2-4: stable machine-readable rejection (frontend can
                // distinguish accepted vs rejected without internal details).
                return ['invalid' => true, 'reason' => $validation['reason'] ?? 'not_eligible'];
            }

            $coupon = $validation['coupon'];

            $result = $this->updateCartTotalPrice($cart, $coupon);
            return $result;
        });
    }

    private function updateCartTotalPrice($cart, $coupon)
    {
        $couponTotal = CouponCalculator::calculate($coupon, (float) $cart->total_price);
        $totalPriceForCart = $couponTotal['finalPrice'];
        $cart->forceFill([
            'coupon' => $coupon->code,
        ])->save();

        return [
            'total_price' => $totalPriceForCart,
            'coupon_discount' => round((float) $cart->total_price - (float) $totalPriceForCart, 2),
            'free_shipping' => $couponTotal['freeShipping'] ?? false,
        ];
    }
}
