<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;

class UpdatePromotionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            "name" => "sometimes|array",
            'name.*' => ['required_with:name', UniqueTranslationRule::for('promotions', 'name')->ignore($this->route('promotion'))],

            "image-desktop" => "sometimes|image|mimes:jpeg,png,jpg,webp,gif",
            "image-mobile"  => "sometimes|image|mimes:jpeg,png,jpg,webp,gif",

            'type'        => ['sometimes', Rule::in(PromotionType::getValues())],
            'type_amount' => ['sometimes', Rule::in(PromotionMountType::getValues())],



            'product_ids' => [
                'sometimes',
                'array',
                Rule::prohibitedIf(function () {
                    return request()->input('apply_to') === 'all_products';
                }),
            ],
            'product_ids.*' => 'exists:products,id',

            'gift_product_ids'   => ['sometimes', 'array', 'min:1'],
            'gift_product_ids.*' => 'exists:products,id',

            'gift_products' => ['sometimes', 'array', 'min:1'],
            'gift_products.*.product_id' => 'required_with:gift_products|exists:products,id',
            'gift_products.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'gift_products.*.quantity'   => 'sometimes|integer|min:1',


            'discount' => [
                'sometimes',
                'numeric',
                'min:0',
                Rule::requiredIf(function () {
                    $type = request()->input('type');
                    $giftIds = request()->input('gift_product_ids', []);
                    $giftProducts = request()->input('gift_products', []);

                    return !(
                        $type === 'quantity' &&
                        (!empty($giftIds) || !empty($giftProducts))
                    );
                }),
            ],

            'max_discount_amount' => 'sometimes|numeric|min:1',

            'required_quantity_type' => 'sometimes|integer|min:1',

            'minimum_order_amount' => [
                'sometimes',
                'numeric',
                'min:0',
                Rule::requiredIf(function () {
                    $type = request()->input('type');

                    return $type !== 'quantity';
                }),
            ],

            'apply_to' => ['nullable', Rule::in(['all_products', 'specific_products'])],
            'limiter'  => 'nullable|integer|min:1',

            'start_at' => 'nullable|date',
            'end_at'   => 'nullable|date|after_or_equal:start_at',

            'status'   => 'sometimes|in:0,1',
        ];
    }


    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
