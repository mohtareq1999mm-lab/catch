<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Contracts\Validation\Validator;

class CountryStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name.en' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('countries')],
            'name.ar' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('countries')],
            'phone_code' => ['nullable', 'string', 'max:5'],
            'status' => ['nullable', 'in:1,0'],
        ];
    }
    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}