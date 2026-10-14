<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Marvel\Enums\ShippingMethod;

class CartCreateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'item' => ['required', 'array', 'min:1'],
            'item.product_id' => ['required', 'integer', 'exists:products,id'],
            'item.quantity' => ['required', 'integer', 'min:1', 'max:' . max(1, (int) config('cart.max_item_quantity', 100))],
            'item.product_variant_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variants,id'],
            'item.attributes' => ['sometimes', 'array'],
            'item.shipping_method' => ['required', 'string', Rule::in([ShippingMethod::SCHEDULED, ShippingMethod::FAST, 'scheduled', 'fast'])],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
