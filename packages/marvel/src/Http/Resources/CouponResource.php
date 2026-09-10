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
            'is_assigned'   => $this->relationLoaded('assignments') ? $this->assignments->isNotEmpty() : $this->assignments()->exists(),
            'assignments'   => $this->relationLoaded('assignments')
                ? $this->assignments->toArray()
                : $this->assignments()->get()->toArray(),
            'created_at'    => $this->created_at?->toIso8601String(),
        ];
    }

    private function roundMoney($value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        return round((float) $value, 2);
    }
}
