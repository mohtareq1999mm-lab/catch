<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class CreatePackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fulfillment_id' => ['required', 'integer', 'min:1'],
            'packing_task_id' => ['nullable', 'integer', 'min:1'],
            'weight' => ['nullable', 'numeric', 'gt:0'],
            'dimensions' => ['nullable', 'array', 'min:1'],
            'dimensions.*' => ['numeric'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }
}
