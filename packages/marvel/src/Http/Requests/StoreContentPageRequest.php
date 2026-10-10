<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

class StoreContentPageRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'title' => 'required|array',
            'title.en' => 'required|string|max:30',
            'title.*' => ['nullable', 'string', 'max:30', UniqueTranslationRule::for('content_pages', 'title')],
        ];
    }


    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}