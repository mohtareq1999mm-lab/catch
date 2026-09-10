<?php

namespace Marvel\Http\Resources;

use Illuminate\Http\Request;

class PickupLocationResource extends Resource
{
    public function toArray(Request $request)
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
            'created_at' => $this->created_at,
        ];
    }
}