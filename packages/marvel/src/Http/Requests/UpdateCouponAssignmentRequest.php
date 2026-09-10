<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdateCouponAssignmentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'max_uses' => [
                'sometimes',
                'integer',
                'min:1',
            ],
            'expires_at' => [
                'nullable',
                'date',
                'after:now',
            ],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => __('validation.given_data_invalid'),
            'errors' => $validator->errors(),
        ], 422));
    }
}
