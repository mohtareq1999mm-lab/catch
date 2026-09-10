<?php

namespace App\Http\Requests\Shipment;

use App\Enums\ShipmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

class UpdateShipmentStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', new Enum(ShipmentStatus::class)],
            'notes' => 'nullable|string|max:2000',
        ];
    }
}
