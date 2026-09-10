<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class CityUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'governorate_id' => ['sometimes', 'integer', 'exists:governorates,id'],
            'name.en' => ['sometimes', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('cities')->ignore($this->route('city'))],
            'name.ar' => ['sometimes', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('cities')->ignore($this->route('city'))],
            'status' => ['nullable', 'in:1,0'],
        ];
    }

      public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}