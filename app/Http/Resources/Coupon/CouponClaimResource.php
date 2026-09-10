<?php

namespace App\Http\Resources\Coupon;

use Illuminate\Http\Resources\Json\JsonResource;
use Marvel\Database\Models\CouponClaim;

class CouponClaimResource extends JsonResource
{
    /**
     * @var CouponClaim
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'coupon_id' => $this->resource->coupon_id,
            'user_id' => $this->resource->user_id,
            'claimed_at' => $this->resource->claimed_at?->toIso8601String(),
            'eligibility_snapshot' => $this->resource->eligibility_snapshot,
            'created_at' => $this->resource->created_at?->toIso8601String(),
        ];
    }
}
