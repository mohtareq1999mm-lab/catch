<?php

namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRateModeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'mode' => strtolower(trim((string) $this->input('mode'))),
        ]);
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'in:auto,manual'],
            'manual_rate' => [
                'nullable',
                'regex:/^\d+(?:\.\d{1,10})?$/',
                'gt:0',
            ],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->sometimes('manual_rate', ['required'], fn ($input) => $input->mode === 'manual');
    }
}
