<?php

namespace App\Http\Resources\Coupons;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'       => $this->getTranslation('name', app()->getLocale()),
            'slug' => $this->slug,
            'code' => $this->code,
            'image'       => [
                'desktop' => $this?->getFirstMediaUrl('coupons-desktop'),
                'mobile' => $this?->getFirstMediaUrl('coupons-mobile'),
            ],
            'borderColor'   => $this->border_color ?? null,
            'borderless'    => (bool) ($this->borderless ?? false),

        ];
    }
}
