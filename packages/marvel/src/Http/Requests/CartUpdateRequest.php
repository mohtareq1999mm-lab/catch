<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\CartOperation;
use Marvel\Enums\ShippingMethod;

class CartUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'item' => ['required', 'array', 'min:1'],
            'item.product_id' => ['required_with:item', 'integer', 'exists:products,id'],
            'item.quantity' => ['required_with:item', 'integer', 'min:1'],
            'item.product_variant_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variants,id'],
            'item.attributes' => ['sometimes', 'array'],
            'item.shipping_method' => ['sometimes', 'string', Rule::in([ShippingMethod::SCHEDULED, ShippingMethod::FAST, 'scheduled', 'fast'])],
            'item.operation' => ['required', 'string', Rule::in([CartOperation::INCREMENT, CartOperation::DECREMENT])],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
