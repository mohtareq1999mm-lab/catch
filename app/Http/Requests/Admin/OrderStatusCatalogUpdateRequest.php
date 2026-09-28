<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class OrderStatusCatalogUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Bilingual display name: plain string sets the `en` translation
            // (legacy clients keep working); {en, ar} sets both.
            'name' => ['sometimes', 'required'],
            'name.en' => ['sometimes', 'required', 'string', 'max:100'],
            'name.ar' => ['nullable', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:500'],
            'is_active' => ['sometimes', 'required', 'boolean'],
        ];
    }

    protected function prepareForValidation(): void
    {
        // Legacy string name -> {en} so the model always stores bilingual JSON.
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => ['en' => $this->input('name')]]);
        }
    }
}
