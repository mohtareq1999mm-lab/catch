<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListBatchesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', 'in:pending,assigned,picking,completed,cancelled'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
