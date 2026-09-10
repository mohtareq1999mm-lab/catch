<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CityStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'governorate_id' => ['required', 'integer', 'exists:governorates,id'],
            'name.en' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('cities')],
            'name.ar' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('cities')],
            'status' => ['nullable', 'in:1,0'],
        ];
    }
    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}