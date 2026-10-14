<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class BrandCreateRequest extends FormRequest
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
            'name' => ['required', 'array'],
            'name.*' => ['required', 'string', UniqueTranslationRule::for('brands', 'name')],
            'image-desktop' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'image-mobile' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'details' => ['sometimes', 'array'],
            'details.*' => ['required_with:details', 'string', 'min:3', 'max:2500'],
            'status' => ['sometimes', 'in:1,0'],
            "products" => "sometimes|array",
            "products.*" => "integer|exists:products,id",
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}