<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ReleaseFulfillmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Single or bulk support: either a single order_id or an array of order_ids.
            'order_id' => ['required_without:order_ids', 'integer'],
            'order_ids' => ['required_without:order_id', 'array', 'min:1', 'max:100'],
            'order_ids.*' => ['integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            // Domain idempotency key. The `auto-release-order-*` namespace is
            // reserved for the automatic payment/COD release mechanism and
            // must never be supplied by operators.
            'idempotency_key' => ['nullable', 'string', 'max:64', 'not_regex:/^auto-release-order-/'],
        ];
    }
}
