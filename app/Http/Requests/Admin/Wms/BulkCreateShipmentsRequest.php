<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class BulkCreateShipmentsRequest extends FormRequest
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
            'courier' => ['nullable', 'string', 'max:100'],
            'shipping_method' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }
}
