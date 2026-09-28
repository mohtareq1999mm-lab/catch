<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class OrderFlowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => \App\Support\LocalizedName::for($this->resource, 'name'),
            'shipping_type' => $this->shipping_type,
            'is_default' => (bool) $this->is_default,
            'is_active' => (bool) $this->is_active,
            'statuses' => $this->when(
                $this->relationLoaded('statuses'),
                fn () => $this->statuses->map(fn ($status) => [
                    'id' => $status->id,
                    'code' => $status->code,
                    'name' => \App\Support\LocalizedName::for($status, 'name'),
                    'is_active' => (bool) $status->is_active,
                    'sort_order' => (int) $status->pivot->sort_order,
                ])->values()->all()
            ),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
