<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Rule;

class FastCheckoutRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'user_phone' => ['required', 'string', 'max:255'],
            'user_email' => ['nullable', 'sometimes', 'email', 'max:255'],
            'address' => ['required', 'array'],
            'notes' => ['nullable', 'string'],
            'governorate_id' => ['required', 'integer', 'exists:governorates,id'],
            'selected_promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'selected_gift_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'fulfillment_type' => ['nullable', 'string', 'in:delivery,pickup'],
            'payment_method' => ['nullable', 'string', 'in:online,cod,pay_at_cashier'],
            'gateway' => ['nullable', 'string', 'max:50'],
            // Fast Shipping is LOCAL ONLY: the value is derived server-side,
            // but an explicit non-local value is rejected (422) instead of
            // silently coerced, so clients cannot drift into another Flow.
            'shipping_type' => ['nullable', 'string', Rule::in([\App\Services\OrderFlow\OrderFlowService::SHIPPING_LOCAL])],
            // Same checkout input gate as normal checkout (validated against
            // the local Flow inside FastShippingService; unknown keys fail).
            'flow_values' => ['nullable', 'array'],
            'pickup_location_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->input('fulfillment_type') === 'pickup'),
                'exists:pickup_locations,id',
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
