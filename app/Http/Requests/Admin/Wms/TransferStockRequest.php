<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class TransferStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'source_location_id' => ['required', 'integer', 'exists:locations,id'],
            'destination_location_id' => ['required', 'integer', 'exists:locations,id'],
            'quantity' => ['required', 'integer', 'min:1', 'max:2147483647'],
            'reference' => ['nullable', 'string', 'max:255'],
            'idempotency_key' => ['nullable', 'string', 'max:64'],
        ];
    }
}
