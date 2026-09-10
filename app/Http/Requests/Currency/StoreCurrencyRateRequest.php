<?php

namespace App\Http\Requests\Currency;

use Illuminate\Foundation\Http\FormRequest;

class StoreCurrencyRateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'currency_id' => ['required', 'integer', 'exists:currencies,id'],
            'exchange_rate' => ['required', 'numeric', 'gt:0'],
            'effective_date' => ['required', 'date'],
        ];
    }
}
