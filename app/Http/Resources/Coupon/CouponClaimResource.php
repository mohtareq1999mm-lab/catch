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
        // P1-2: customer-safe shape only. NEVER expose eligibility_snapshot
        // (internal rule evaluation / targeting config), user_id scoping is
        // implicit (owner endpoint), no debug internals.
        $status = $this->resource->status;
        $statusValue = $status instanceof \BackedEnum ? $status->value : (string) $status;

        return [
            'id' => $this->resource->id,
            'coupon_id' => $this->resource->coupon_id,
            'code' => $this->resource->coupon?->code,
            'status' => $statusValue,
            'claimed_at' => $this->resource->claimed_at?->toIso8601String(),
            'expires_at' => $this->resource->expires_at?->toIso8601String(),
            'redeemed_at' => $this->resource->redeemed_at?->toIso8601String(),
        ];
    }
}
