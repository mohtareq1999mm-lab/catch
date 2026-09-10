<?php

namespace App\Http\Resources\PickupLocation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PickupLocationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'store_name' => $this->store_name,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'working_hours' => $this->working_hours,
            'status' => (bool) $this->status,
            'display_order' => $this->display_order,
            'is_default' => (bool) $this->is_default,
        ];
    }
}
