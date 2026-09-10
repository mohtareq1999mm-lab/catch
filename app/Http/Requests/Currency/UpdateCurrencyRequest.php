<?php

namespace App\Http\Requests\Currency;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'array'],
            'name.*' => ['sometimes', 'required', 'string', 'max:255',UniqueTranslationRule::for('currencies', 'name')->ignore($this->route('currency'))],
            'symbol' => ['nullable', 'array'],
            'symbol.*' => ['nullable', 'string', 'max:255',UniqueTranslationRule::for('currencies', 'symbol')->ignore($this->route('currency'))],
            'country_name' => ['nullable', 'array'],
            'country_name.*' => ['nullable', 'string', 'max:255',UniqueTranslationRule::for('currencies', 'country_name')->ignore($this->route('currency')) ],
            'numeric_code' => ['nullable', 'string', 'max:3'],
            'decimal_places' => ['nullable', 'integer', 'min:0', 'max:4'],
            'icon' => ['nullable', 'string', 'max:255'],
            'is_active' => ['nullable', 'boolean'],
            'sort_order' => ['nullable', 'integer'],
        ];
    }
}
