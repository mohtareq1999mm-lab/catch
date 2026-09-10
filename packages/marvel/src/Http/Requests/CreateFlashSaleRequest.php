<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;
use Marvel\Enums\FlashSaleType;

class CreateFlashSaleRequest extends FormRequest
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
            'title'        => ['required', 'array'],
            'title.*' => ['required', 'string', 'min:3', 'max:70', UniqueTranslationRule::for('flash_sales', "title")],
            'description'        => ['required', 'array'],
            'description.*'  => ['required', 'string', 'max:1000'],
            'image-desktop'        => ['required', 'image', 'mimes:jpeg,png,jpg,webp,gif'],
            'image-mobile'        => ['required', 'image', 'mimes:jpeg,png,jpg,webp,gif'],
            'start_date'   => ['required', 'date'],
            'end_date'     => ['required', 'date', 'after_or_equal:start_date'],
            'type' => ['required', Rule::in(FlashSaleType::getValues())],
            'discount' => ['required', 'numeric', 'min:0'],
            'max_discount_amount' => [
                'required_if:type,percentage',
                'numeric',
                'min:1'
            ],
            'status' => ['required', 'in:1,0'],
            "products" => "sometimes|array",
            "products.*" => "integer|exists:products,id",

        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}