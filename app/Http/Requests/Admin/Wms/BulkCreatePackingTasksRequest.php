<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class BulkCreatePackingTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fulfillment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'fulfillment_ids.*' => ['integer', 'min:1'],
            'packing_station_id' => ['nullable', 'integer', 'exists:packing_stations,id'],
        ];
    }
}
