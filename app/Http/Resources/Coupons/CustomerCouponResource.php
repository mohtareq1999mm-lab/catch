<?php

namespace App\Http\Resources\Coupons;

use App\Services\Coupon\Discovery\CouponDiscoveryPolicy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Customer coupon discovery shape for listing endpoints.
 *
 * Superset of Coupons\CouponResource (same base fields) plus the canonical
 * discovery decision from CouponDiscoveryPolicy: visibility, requires_claim,
 * and the conditionally exposed code. The decision is attached by the
 * calling service as the `discoveryDecision` relation; the resource falls
 * back to evaluating the policy live so direct uses stay correct.
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
            : app(CouponDiscoveryPolicy::class)->decide($coupon, $request->user());

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
            'code' => $decision['can_expose_code'] ? $coupon->code : null,
        ];
    }
}
