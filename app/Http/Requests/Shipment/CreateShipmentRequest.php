<?php

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;

class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_id' => 'required|integer|exists:orders,id',
            'tracking_number' => 'nullable|string|max:100|unique:shipments,tracking_number',
            'courier' => 'nullable|string|max:50',
            'shipping_method' => 'nullable|string|max:30',
            'shipping_cost' => 'nullable|numeric|min:0',
            'currency' => 'nullable|string|max:3',
            'origin_address' => 'nullable|array',
            'destination_address' => 'nullable|array',
            'items' => 'nullable|array',
            'total_weight' => 'nullable|numeric|min:0',
            'weight_unit' => 'nullable|string|max:10',
            'estimated_delivery_at' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
            'metadata' => 'nullable|array',
        ];
    }
}
