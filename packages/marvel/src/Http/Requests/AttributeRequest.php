<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class AttributeRequest extends FormRequest
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
            'name.en' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('attributes')->ignore($this->route('attribute'))],
            'name.ar' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('attributes')->ignore($this->route('attribute'))],

            'values' => [
                'sometimes',
                'array',
            ],
            'values.*' => [
                'sometimes',
                'array',
            ],
            'values.*.value' => [
                'required',
                'array',
            ],
            'values.*.value.en' => [
                'required',
                'string',
                'min:2',
                'max:50',
            ],
            'values.*.value.ar' => [
                'required',
                'string',
                'min:2',
                'max:50',
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
