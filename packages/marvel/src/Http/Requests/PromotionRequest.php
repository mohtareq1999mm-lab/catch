<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\PromotionMountType;
use Marvel\Enums\PromotionType;

class PromotionRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            "name" => "required|array",
            'name.*' => ['required_with:name', UniqueTranslationRule::for('promotions', 'name')],

            "image-desktop" => "required|image|mimes:jpeg,png,jpg,webp,gif",
            "image-mobile" => "required|image|mimes:jpeg,png,jpg,webp,gif",
            'type' => ['required', Rule::in(PromotionType::getValues())],
            'type_amount' => ['required', Rule::in(PromotionMountType::getValues())],

            'product_ids' => [
                Rule::requiredIf(function () {
                    return request()->input('apply_to') === 'specific_products';
                }),
                Rule::prohibitedIf(function () {
                    return request()->input('apply_to') === 'all_products';
                }),
                'array',
            ],
            'product_ids.*' => 'exists:products,id',
            'gift_products' => ['required_if:type_amount,gift', 'array', 'min:1'],
            'gift_products.*.product_id' => 'required_with:gift_products|exists:products,id',
            'gift_products.*.product_variant_id' => 'nullable|exists:product_variants,id',
            'gift_products.*.quantity'   => 'sometimes|integer|min:1',

            'discount' => [
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

            'max_discount_amount' => [
                'required_if:type_amount,percentage',
                'numeric',
                'min:1'
            ],

            'required_quantity_type' => [
                'integer',
                'min:1',
                Rule::requiredIf(function () {
                    return request()->input('type') === 'quantity';
                }),
            ],
            'minimum_order_amount' => [
                'numeric',
                'min:0',
                Rule::requiredIf(function () {
                    $type = request()->input('type');

                    return $type !== 'quantity';
                }),
            ],

            'apply_to' => ['required', Rule::in(['all_products', 'specific_products'])],
            'limiter' => 'sometimes|integer|min:1',

            'start_at' => 'sometimes|date',
            'end_at'   => 'sometimes|date|after_or_equal:start_at',

            'status'   => 'sometimes|in:0,1',
        ];
    }


    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
