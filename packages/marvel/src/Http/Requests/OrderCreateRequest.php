<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\FulfillmentType;

class OrderCreateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $fulfillmentValues = [FulfillmentType::DELIVERY, FulfillmentType::PICKUP];

        // D4 — a cart with no physical lines does not require delivery data.
        $requiresShipping = $this->cartHasPhysicalItems();

        return [
            'name' => ['required', 'string', 'max:255'],
            'user_phone' => ['required', 'string', 'max:255'],
            'user_email' => ['nullable', 'sometimes', 'email', 'max:255'],
            'address' => [
                Rule::requiredIf(fn () => $requiresShipping && $this->input('fulfillment_type') !== FulfillmentType::PICKUP),
                'nullable',
                'array',
            ],
            'notes' => ['nullable', 'string'],
            'selected_promotion_id' => ['nullable', 'integer', 'exists:promotions,id'],
            'selected_gift_product_id' => ['nullable', 'integer', 'exists:products,id'],
            'type' => ['nullable', 'in:mobile,web'],
            'fulfillment_type' => [
                'nullable',
                'string',
                Rule::in($this->input('payment_method') === 'pay_at_cashier'
                    ? [FulfillmentType::PICKUP]
                    : $fulfillmentValues
                ),
            ],
            'payment_method' => ['nullable', 'string', 'in:online,cod,pay_at_cashier'],
            'gateway' => ['nullable', 'string', 'max:50'],
            'governorate_id' => [
                Rule::requiredIf(fn () => $requiresShipping && $this->input('fulfillment_type') === FulfillmentType::DELIVERY),
                'integer',
                'exists:governorates,id',
            ],
            'pickup_location_id' => [
                'nullable',
                'integer',
                Rule::requiredIf(fn () => $this->input('fulfillment_type') === FulfillmentType::PICKUP),
                'exists:pickup_locations,id',
            ],
        ];
    }

    /**
     * D4 — true when the active cart contains at least one PHYSICAL line
     * (including gifts). Digital-only carts ship nothing.
     */
    private function cartHasPhysicalItems(): bool
    {
        $user = $this->user();

        if (!$user) {
            return true;
        }

        return \Marvel\Database\Models\CartItem::query()
            ->whereHas('cart', fn ($q) => $q->where('user_id', $user->id)->where('status', 'active'))
            ->whereHas('product', fn ($q) => $q->where(function ($inner) {
                $inner->whereNull('item_type')->orWhere('item_type', '!=', \Marvel\Enums\ItemType::DIGITAL);
            }))
            ->exists();
    }


    public function messages(): array
    {
        $messages = [];
        if ($this->input('payment_method') === 'pay_at_cashier') {
            $messages['fulfillment_type.in'] = __('checkout.pay_at_cashier_requires_pickup');
        }
        return $messages;
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}