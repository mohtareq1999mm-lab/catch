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
            'override' => ['sometimes', 'boolean'],
        ];
    }
}
