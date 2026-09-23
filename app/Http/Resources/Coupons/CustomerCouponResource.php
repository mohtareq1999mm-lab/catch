<?php

namespace App\Http\Resources\Coupons;

use App\Services\Coupon\Discovery\CouponDiscoveryPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer coupon discovery shape for listing endpoints.
 *
 * Pure renderer of the canonical CouponDiscoveryPolicy decision
 * (visibility, requires_claim, eligible, claim_status, action, code) —
 * it contains no business rules of its own. The decision (+`userClaim`
 * when signed in) is attached by the calling service as relations; the
 * resource falls back to evaluating live so direct uses stay correct.
 *
 * Homepage and other anonymous surfaces keep using CouponResource (never
 * any code) — this resource serves customer discovery endpoints only.
 */
class CustomerCouponResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        /** @var \Marvel\Database\Models\Coupon $coupon */
        $coupon = $this->resource;

        $decision = $coupon->relationLoaded('discoveryDecision')
            ? $coupon->getRelation('discoveryDecision')
            : app(CouponDiscoveryPolicy::class)->decide(
                $coupon,
                $request->user(),
                $coupon->relationLoaded('userClaim') ? $coupon->getRelation('userClaim') : null,
            );

        return [
            'id' => $coupon->id,
            'name' => $coupon->getTranslation('name', app()->getLocale()),
            'slug' => $coupon->slug,
            'image' => [
                'desktop' => $coupon?->getFirstMediaUrl('coupons-desktop'),
                'mobile' => $coupon?->getFirstMediaUrl('coupons-mobile'),
            ],
            'borderColor' => $coupon->border_color ?? null,
            'borderless' => (bool) ($coupon->borderless ?? false),
            'visibility' => $decision['visibility'],
            'requires_claim' => $decision['requires_claim'],
            'eligible' => $decision['eligible'],
            'claim_status' => $decision['claim_status'],
            'action' => $decision['action'],
            'code' => $decision['can_expose_code'] ? $coupon->code : null,
        ];
    }
}
