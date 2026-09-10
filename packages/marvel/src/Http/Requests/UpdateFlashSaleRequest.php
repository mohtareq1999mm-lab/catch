<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Validation\Rule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Marvel\Enums\FlashSaleType;

class UpdateFlashSaleRequest extends FormRequest
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
        // $language = $this->language ?? DEFAULT_LANGUAGE;

        $id = $this->route("flash_sale");
        $rules =  [
            'title'        => ['sometimes', 'array'],
            'title.*' => ['sometimes', 'string', 'min:3', 'max:70', UniqueTranslationRule::for('flash_sales', "title")->ignore($id)],
            'description'        => ['sometimes', 'array'],
            'description.*'  => ['sometimes', 'string', 'max:1000'],
            'image-desktop'        => ['sometimes', 'image', 'mimes:jpeg,png,jpg,webp,gif'],
            'image-mobile'        => ['sometimes', 'image', 'mimes:jpeg,png,jpg,webp,gif'],
            'start_date'   => ['sometimes', 'date'],
            'end_date'     => ['sometimes', 'date', 'after_or_equal:start_date'],
            'type' => ['sometimes', Rule::in(FlashSaleType::getValues())],
            'discount' => ['sometimes', 'numeric', 'min:0'],
            'max_discount_amount' => [
                'required_if:type,percentage',
                'numeric',
                'min:1'
            ],
            'status' => ['sometimes', 'in:1,0'],
            "products" => "sometimes|array",
            "products.*" => "integer|exists:products,id",

        ];
        return $rules;
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}