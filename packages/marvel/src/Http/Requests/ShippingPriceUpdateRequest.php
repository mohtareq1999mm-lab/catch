<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ShippingPriceUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $shippingPriceId = $this->route('shipping_price');

        return [
            'governorate_id' => [
                'sometimes',
                'integer',
                'exists:governorates,id',
                Rule::unique('shipping_prices', 'governorate_id')->ignore($shippingPriceId),
            ],
            'price' => ['sometimes', 'numeric', 'min:0'],
            'estimated_days' => ['nullable', 'integer', 'min:1'],
            'free_shipping_over' => ['nullable', 'numeric', 'min:0'],
            'status' => ['nullable', 'boolean'],
        ];
    }

      public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}