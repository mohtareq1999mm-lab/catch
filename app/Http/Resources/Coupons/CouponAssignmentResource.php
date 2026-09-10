<?php

namespace App\Http\Resources\Coupons;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponAssignmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'coupon_id' => $this->coupon_id,
            'user_id' => $this->user_id,
            'user' => $this->whenLoaded('user', fn() => [
                'id' => $this->user->id,
                'name' => $this->user->name,
                'email' => $this->user->email,
            ]),
            'max_uses' => $this->max_uses,
            'used' => $this->used,
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'expires_at' => $this->expires_at?->toIso8601String(),
        ];
    }
}
