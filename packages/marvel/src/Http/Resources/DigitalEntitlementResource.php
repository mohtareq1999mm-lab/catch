<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class DigitalEntitlementResource extends Resource
{
    public function toArray($request): array
    {
        return [
            'uuid'           => $this->uuid,
            'status'         => $this->status,
            'download_limit' => (int) $this->download_limit,
            'unlimited'      => (int) $this->download_limit === 0,
            'download_count' => (int) $this->download_count,
            'delivered_at'   => $this->delivered_at?->toIso8601String(),
            'revoked_at'     => $this->revoked_at?->toIso8601String(),
            'expires_at'     => $this->expires_at?->toIso8601String(),
            'order_id'       => $this->order_id,
            'order_product_id' => $this->order_product_id,
            'user'           => $this->whenLoaded('user', fn () => [
                'id'    => $this->user?->id,
                'name'  => $this->user?->name,
                'email' => $this->user?->email,
            ]),
            'product'        => $this->whenLoaded('orderItem', fn () => [
                'id'   => $this->orderItem?->product_id,
                'name' => $this->orderItem?->product_name,
            ]),
            'created_at'     => $this->created_at?->toIso8601String(),
        ];
    }
}
