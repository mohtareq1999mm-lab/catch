<?php


namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;


class TagUpdateRequest extends FormRequest
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
            'name'     => ['array', 'sometimes'],
            'name.*'   => ['string', 'max:150', 'sometimes', UniqueTranslationRule::for('tags', 'name')->ignore($this->route('tag'))],
            'products' => ['nullable', 'array'],
            'products.*' => ['integer', 'exists:products,id'],
            'icon'     => ['nullable', 'string'],
            'image'    => ['nullable', 'image'],
        ];
    }

    /**
     * Get the error messages that apply to the request parameters.
     *
     * @return array
     */
    public function messages()
    {
        return [];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
