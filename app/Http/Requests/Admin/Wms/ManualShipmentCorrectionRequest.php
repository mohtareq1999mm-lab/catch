<?php

namespace App\Http\Requests\Admin\Wms;

use App\Enums\ShipmentStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ManualShipmentCorrectionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(array_column(ShipmentStatus::cases(), 'value'))],
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
