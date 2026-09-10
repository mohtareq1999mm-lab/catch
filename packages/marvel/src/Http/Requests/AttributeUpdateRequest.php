<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class AttributeUpdateRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        $id = $this->route('attribute');
        return [
            'name' => ['sometimes', 'array'],
            'name.en' => ['sometimes', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('attributes')->ignore($id)],
            'name.ar' => ['sometimes', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('attributes')->ignore($id)],

            'values' => [
                'sometimes',
                'array',
            ],
            'values.*' => [
                'sometimes',
                'array',
            ],
            'values.*.value' => [
                'required_with:values.*',
                'array',
            ],
            'values.*.value.en' => [
                'required_with:values.*.value',
                'string',
                'min:2',
                'max:50',
            ],
            'values.*.value.ar' => [
                'required_with:values.*.value',
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
