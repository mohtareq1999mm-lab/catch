<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class ShippingPriceStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'governorate_id' => ['required', 'integer', 'exists:governorates,id', 'unique:shipping_prices,governorate_id'],
            'price' => ['required', 'numeric', 'min:0'],
            'estimated_days' => ['nullable', 'integer', 'min:1'],
            'free_shipping_over' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];
    }

      public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}