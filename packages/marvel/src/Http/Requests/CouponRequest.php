<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Enums\DiscountType;

class CouponRequest extends FormRequest
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

        return [
            "name" => "required|array",
            'name.*' => ['required_with:name', UniqueTranslationRule::for('coupons', 'name')],
            'image-desktop' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,gif'],
            'image-mobile' => ['required', 'image', 'mimes:jpeg,png,jpg,webp,gif'],
            'border_color' => ['nullable', 'string', 'max:50'],
            'borderless' => ['sometimes', 'in:1,0'],
            'discount'      => 'required|numeric|min:0',
            'discount_type' => ['required', Rule::in(DiscountType::getValues())],
            'max_discount_amount' => [
                'required_if:discount_type,percentage',
                'numeric',
                'min:1'
            ],
            'start_date'    => 'required|date_format:Y-m-d',
            'end_date'      => 'required|date_format:Y-m-d|after_or_equal:start_date',
            'limiter'       => 'nullable|integer|min:0',
            'status'        => 'sometimes|in:1,0',
        ];
    }

    /**
     * Get the error messages that apply to the request parameters.
     *
     * @return array
     */



    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}