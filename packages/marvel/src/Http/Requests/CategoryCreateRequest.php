<?php


namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;


class CategoryCreateRequest extends FormRequest
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
            'name.*' => ['required', 'string', UniqueTranslationRule::for('categories', 'name')],
            'image-desktop' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'image-mobile' => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id'],
            'details'      => ['sometimes', 'string', 'min:3', 'max:2500'],
            "products" => "sometimes|array",
            "products.*" => "exists:products,id",
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
