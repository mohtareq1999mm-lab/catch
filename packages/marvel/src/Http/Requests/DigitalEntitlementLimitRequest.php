<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DigitalEntitlementLimitRequest extends FormRequest
{
    public function authorize()
    {
        return true;   // authorization via permission middleware
    }

    public function rules()
    {
        // `limit` is optional: omitting it means UNLIMITED (sentinel 0).
        // Explicit null/0 also map to unlimited; positive ints are caps.
        return [
            'limit' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
