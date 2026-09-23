<?php

namespace Marvel\Http\Resources;

use App\Services\Coupon\CouponValidator;
use Illuminate\Http\Request;

class CouponResource extends Resource
{
    /**
     * Transform the resource into an array.
     *
     * @param  Request  $request
     * @return array
     */
    public function toArray($request)
    {
        // Single authoritative audience resolution per row: the resolver
        // owns is_public × has_assignments × has_targeting composition.
        // This resource only formats (no business logic here).
        $audience = app(\App\Services\Coupon\Audience\CouponAudienceResolver::class)
            ->resolve($this->resource);

        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'name'          => request()->routeIs('coupons.show') ? [
                'ar' => $this->getTranslation('name', 'ar'),
                'en' => $this->getTranslation('name', 'en'),
            ] : $this->getTranslation('name', app()->getLocale()),
            'image'         => [
                'desktop' => $this->getFirstMediaUrl('coupons-desktop') ?: null,
                'mobile'  => $this->getFirstMediaUrl('coupons-mobile') ?: null,
            ],
            'borderColor'   => $this->border_color ?? null,
            'borderless'    => (bool) ($this->borderless ?? false),
            'discount'      => $this->discount,
            'discount_type' => $this->typeByLang(),
            'max_discount_amount' => $this->roundMoney($this->max_discount_amount),
            'start_date'    => $this->start_date,
            'end_date'      => $this->end_date,
            'limiter'       => $this->limiter,
            'used'          => $this->used,
            'status'        => (bool) $this->status,
            'is_valid'      => CouponValidator::validate($this->resource)['valid'],
            // Authoritative composite audience (see CouponAudienceResolver).
            'audience'      => $audience,
            // Legacy compatibility fields (documented meanings):
            // is_assigned = has assignment rows; audience_type = composite
            // label (pre-flag values keep exact meaning); targeting_mode =
            // eligibility evaluation mode only, never publicity.
            'is_assigned'   => $audience['has_assignments'],
            'audience_type' => $audience['type'],
            'targeting_mode' => $this->relationLoaded('targeting')
                ? ($this->targeting?->mode ?? null)
                : optional($this->targeting()->first())->mode,
            'targeting'     => $this->relationLoaded('targeting') && $this->targeting !== null
                ? \App\Http\Resources\Coupon\CouponTargetingResource::make($this->targeting)
                : null,
            'assignments'   => $this->presentAssignments(),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * Owner/admin-safe assignment shape (id, quotas, timestamps only —
     * never user PII beyond user_id; the full user object stays in the
     * dedicated assignment endpoints).
     */
    private function presentAssignments(): array
    {
        $rows = $this->relationLoaded('assignments')
            ? $this->assignments
            : $this->assignments()->get();

        return $rows->map(fn ($a) => [
            'id' => $a->id,
            'coupon_id' => $a->coupon_id,
            'user_id' => $a->user_id,
            'max_uses' => $a->max_uses,
            'used' => $a->used,
            'remaining' => max(0, (int) $a->max_uses - (int) $a->used),
            'expires_at' => $a->expires_at?->toIso8601String(),
            'assigned_at' => $a->assigned_at?->toIso8601String(),
        ])->all();
    }

    private function roundMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }
}
