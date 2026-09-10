<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class CreateFaqsRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'faq_title'       => ['required', 'array'],
            'faq_title.*'       => ['required', 'string', 'min:3', 'max:1000'],
            'faq_description' => ['required', 'array'],
            'faq_description.*' => ['required', 'string', 'min:3', 'max:1000'],
            'status'          => ['sometimes', "in:1,0"],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
