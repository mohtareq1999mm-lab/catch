<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWarehouseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Metadata only. Code is immutable via API (stable identifier); status
     * and default move through their explicit commands.
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'address' => ['nullable', 'string'],
            'city' => ['nullable', 'string', 'max:100'],
            'country' => ['nullable', 'string', 'max:100'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
