<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class CreatePackingStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'code' => ['required', 'string', 'max:50', 'unique:packing_stations,code'],
            'name' => ['required', 'string', 'max:100'],
            'status' => ['nullable', 'string', 'in:active,inactive,maintenance'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'daily_capacity' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
