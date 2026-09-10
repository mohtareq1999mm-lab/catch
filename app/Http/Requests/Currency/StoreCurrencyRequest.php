<?php

namespace App\Http\Requests\Currency;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'code' => strtoupper(trim((string) $this->input('code'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'size:3', 'regex:/^[A-Za-z]{3}$/', 'unique:currencies,code'],
            'name' => ['required', 'array'],
            'name.*' => ['required', 'string', 'max:255',UniqueTranslationRule::for('currencies', 'name')],
            'symbol' => ['required', 'array'],
            'symbol.*' => ['required', 'string', 'max:255',UniqueTranslationRule::for('currencies', 'symbol')],
            'country_name' => ['required', 'array'],
            'country_name.*' => ['required', 'string', 'max:255', UniqueTranslationRule::for('currencies', 'country_name')],
            'numeric_code' => ['nullable', 'string', 'max:3'],
            'decimal_places' => ['nullable', 'integer', 'min:0', 'max:4'],
            'icon' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
