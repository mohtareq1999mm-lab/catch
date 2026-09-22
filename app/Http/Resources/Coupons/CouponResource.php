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
    /**
     * CP-02 (INV-08): public shape. The redeemable `code` is NEVER exposed
     * here — this resource serves the unauthenticated listing and homepage.
     * Coupon codes are entered by the customer at apply time; assignment and
     * claim data remain behind authenticated endpoints.
     */
    public function toArray(Request $request): array
    {
        return [
            'id'          => $this->id,
            'name'       => $this->getTranslation('name', app()->getLocale()),
            'slug' => $this->slug,
            'image'       => [
                'desktop' => $this?->getFirstMediaUrl('coupons-desktop'),
                'mobile' => $this?->getFirstMediaUrl('coupons-mobile'),
            ],
            'borderColor'   => $this->border_color ?? null,
            'borderless'    => (bool) ($this->borderless ?? false),

        ];
    }
}
