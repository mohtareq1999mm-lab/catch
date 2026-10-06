<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListPackagesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * fulfillment_id is required: packages are never listed unbounded
     * across warehouses. Scope resolves via the fulfillment's warehouse.
     */
    public function rules(): array
    {
        return [
            'fulfillment_id' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:open,sealed,handed_off,voided'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
