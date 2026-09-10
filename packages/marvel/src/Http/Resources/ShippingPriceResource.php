<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ShippingPriceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'governorate_id' => $this->governorate_id,
            'price' => round((float) $this->price, 2),
            'estimated_days' => $this->estimated_days,
            'free_shipping_over' => $this->free_shipping_over !== null ? round((float) $this->free_shipping_over, 2) : null,
            'status' => (bool) $this->status,
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}