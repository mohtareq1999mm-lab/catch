<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class CreateBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fulfillment_ids' => ['required', 'array', 'min:1', 'max:100'],
            'fulfillment_ids.*' => ['integer', 'distinct', 'exists:fulfillments,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            // HTTP accepts wave only; the service keeps its internal string.
            'type' => ['nullable', 'in:wave'],
        ];
    }
}
