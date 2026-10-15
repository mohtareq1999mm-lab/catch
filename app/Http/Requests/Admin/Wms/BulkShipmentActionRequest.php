<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class BulkShipmentActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'shipment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'shipment_ids.*' => ['integer', 'min:1'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
