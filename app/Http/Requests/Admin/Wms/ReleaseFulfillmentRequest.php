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
            // Existence is resolved in-controller (firstOrFail → 404): a
            // missing order is a missing resource, never a 422 business
            // refusal. Validation only guards shape here.
            'order_id' => ['required', 'integer'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            // Domain idempotency key. The `auto-release-order-*` namespace is
            // reserved for the automatic payment/COD release mechanism and
            // must never be supplied by operators.
            'idempotency_key' => ['nullable', 'string', 'max:64', 'not_regex:/^auto-release-order-/'],
        ];
    }
}
