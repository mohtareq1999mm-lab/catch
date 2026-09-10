<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CountryUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name.en' => ['sometimes', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('countries')->ignore($this->route('country'))],
            'name.ar' => ['sometimes', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('countries')->ignore($this->route('country'))],
            'phone_code' => ['nullable', 'string', 'max:5'],
            'status' => ['nullable', 'in:1,0'],
        ];
    }
      public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}