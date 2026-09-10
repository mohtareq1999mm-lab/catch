<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BrandUpdateRequest extends FormRequest
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
        $id = $this->route('brand');
        return [
            'name' => ['sometimes', 'array'],
            'name.*' => ['sometimes', 'string', UniqueTranslationRule::for('brands')->ignore($id)],
            'image-desktop' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'image-mobile' => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'details' => ['sometimes', 'array'],
            'details.*' => ['required_with:details', 'string', 'min:3', 'max:2500'],
            'status' => ['sometimes', 'in:1,0'],
            "products" => "sometimes|array",
            "products.*" => "integer|exists:products,id",
        ];
    }

    /**
     * Get the error messages that apply to the request parameters.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'name.*.string' => 'Name is not a valid string',
            'name.*.max:255' => 'Name can not be more than 255 character',
            'image.string' => 'Image is not a valid string',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
