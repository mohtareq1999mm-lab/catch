<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class AttributeCreateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('attributes')],
            'name.ar' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('attributes')],

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
