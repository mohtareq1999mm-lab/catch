<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class CompletePackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'weight' => ['required', 'numeric', 'gt:0'],
            'dimensions' => ['required', 'array', 'min:1'],
            'dimensions.*' => ['numeric'],
            'materials' => ['nullable', 'array'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
