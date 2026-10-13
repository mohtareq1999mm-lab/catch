<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class RecordPickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'gt:0'],
            'notes' => ['nullable', 'string', 'max:2000'],
            // Device op sequence for replay protection (mirrors ConfirmPickRequest):
            // a retried request with an already-applied op_seq returns the
            // current state instead of double-counting picked quantity.
            'op_seq' => ['nullable', 'integer', 'min:0'],
            'override' => ['sometimes', 'boolean'],
        ];
    }
}
