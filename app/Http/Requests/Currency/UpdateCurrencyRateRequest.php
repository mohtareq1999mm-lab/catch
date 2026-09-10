<?php

namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCurrencyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
