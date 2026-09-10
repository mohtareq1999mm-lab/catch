<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SelectCurrencyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Accept both JSON (application/json) and FormData (multipart/form-data).
        // Normalize to uppercase trimmed string so validation and controller
        // both see a consistent ISO code. This keeps the documented JSON
        // contract {"currency_code":"AED"} working while preserving FormData.
        $raw = $this->input('currency_code');

        if (is_string($raw)) {
            $this->merge(['currency_code' => strtoupper(trim($raw))]);
        } elseif ($raw !== null) {
            $this->merge(['currency_code' => $raw]);
        }
    }

    public function rules(): array
    {
        return [
            'currency_code' => [
                'required',
                'string',
                'size:3',
                Rule::exists('currencies', 'code')->where('is_active', true),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'currency_code.required' => 'The currency code field is required.',
        ];
    }
}