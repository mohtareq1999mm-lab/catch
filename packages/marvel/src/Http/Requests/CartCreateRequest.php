<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;
use Marvel\Enums\CartOperation;
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
            // The variant must belong to the SAME product: a global exists rule
            // accepted foreign variants and failed later with a generic
            // repository error instead of a field error (approved plan S9).
            'item.product_variant_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('product_variants', 'id')->where(
                    fn ($query) => $query->where('product_id', (int) $this->input('item.product_id'))
                ),
            ],
            'item.attributes' => ['sometimes', 'array'],
            'item.shipping_method' => ['required', 'string', Rule::in([ShippingMethod::SCHEDULED, ShippingMethod::FAST, 'scheduled', 'fast'])],
            // Unknown operations must fail with a validation error instead of
            // silently committing a no-op (approved plan S9).
            'item.operation' => ['sometimes', 'string', Rule::in(CartOperation::getValues())],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
