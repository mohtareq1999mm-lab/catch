<?php

namespace App\Http\Resources\Coupon;

use Illuminate\Http\Resources\Json\JsonResource;
use Marvel\Database\Models\CouponTargeting;

class CouponTargetingResource extends JsonResource
{
    /**
     * @var CouponTargeting
     */
    public $resource;

    public function toArray($request): array
    {
        return [
            'id' => $this->resource->id,
            'coupon_id' => $this->resource->coupon_id,
            'mode' => $this->resource->mode,
            'require_claim' => (bool) $this->resource->require_claim,
            'max_claims' => $this->resource->max_claims !== null ? (int) $this->resource->max_claims : null,
            'claim_ttl_hours' => $this->resource->claim_ttl_hours !== null ? (int) $this->resource->claim_ttl_hours : null,
            'rule_tree' => $this->resource->rule_tree,
            'created_at' => $this->resource->created_at?->toIso8601String(),
            'updated_at' => $this->resource->updated_at?->toIso8601String(),
        ];
    }
}
