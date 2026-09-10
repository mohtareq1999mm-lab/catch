<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class UpdateFaqsRequest extends FormRequest
{

    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'faq_title'         => ['sometimes', 'array'],
            'faq_title.*'       => ['sometimes', 'string', 'min:3', 'max:1000',UniqueTranslationRule::for('faqs', "faq_title")->ignore($this->route("faq"))],
            'faq_description'   => ['sometimes', 'array'],
            'faq_description.*' => ['sometimes', 'string', 'min:3', 'max:1000',UniqueTranslationRule::for('faqs', "faq_description")->ignore($this->route("faq"))],
            'status'            => ['sometimes', 'in:0,1'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
