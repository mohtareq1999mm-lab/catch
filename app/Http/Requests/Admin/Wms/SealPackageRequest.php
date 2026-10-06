<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class SealPackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'weight' => ['nullable', 'numeric', 'gt:0'],
            'dimensions' => ['nullable', 'array', 'min:1'],
            'dimensions.*' => ['numeric'],
        ];
    }
}
