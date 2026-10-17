<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListShipmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * fulfillment_id is optional: when present, scopes to that fulfillment.
     * When omitted, scopes to the target warehouse (user's home warehouse or specified warehouse for managers).
     */
    public function rules(): array
    {
        return [
            'fulfillment_id' => ['nullable', 'integer', 'min:1'],
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
