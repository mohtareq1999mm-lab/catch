<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdatePackingStationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $stationId = (int) $this->route('id');

        return [
            'code' => ['sometimes', 'required', 'string', 'max:50', Rule::unique('packing_stations', 'code')->ignore($stationId)],
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'status' => ['sometimes', 'required', 'string', 'in:active,inactive,maintenance'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'daily_capacity' => ['nullable', 'integer', 'min:1'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
