<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmPickRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Scanner payload: WHERE (location barcode/code), WHAT (product
            // or variant SKU), HOW MUCH, plus the device op sequence for
            // replay protection. All business validation lives in
            // PickingExecutionService::confirm.
            'location' => ['required', 'string', 'max:255'],
            'product' => ['required', 'string', 'max:255'],
            'quantity' => ['required', 'numeric', 'gt:0'],
            'op_seq' => ['nullable', 'integer', 'min:0'],
            'override' => ['sometimes', 'boolean'],
        ];
    }
}
