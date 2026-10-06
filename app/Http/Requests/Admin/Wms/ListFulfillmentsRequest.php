<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListFulfillmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'status' => ['nullable', 'in:pending,picking,picked,packing,ready_to_ship,shipped,delivered,cancelled'],
            'assigned_to' => ['nullable', 'integer', 'exists:users,id'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
