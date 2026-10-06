<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListPickingTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'batch_id' => ['nullable', 'integer', 'exists:fulfillment_batches,id'],
            'fulfillment_id' => ['nullable', 'integer', 'exists:fulfillments,id'],
            'status' => ['nullable', 'in:pending,assigned,picking,picked,skipped,cancelled'],
            'mine' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
