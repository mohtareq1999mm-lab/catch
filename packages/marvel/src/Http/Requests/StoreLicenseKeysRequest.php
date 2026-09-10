<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StoreLicenseKeysRequest extends FormRequest
{
    public function authorize()
    {
        return true;   // authorization via permission middleware
    }

    public function rules()
    {
        return [
            'keys' => ['required', 'array', 'min:1', 'max:' . config('digital.licenses.max_batch_keys', 500)],
            'keys.*' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
