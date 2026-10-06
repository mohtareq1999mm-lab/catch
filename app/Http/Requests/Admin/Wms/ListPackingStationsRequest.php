<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListPackingStationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:active,inactive,maintenance'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
