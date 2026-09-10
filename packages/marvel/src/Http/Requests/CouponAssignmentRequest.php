<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CouponAssignmentRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
            ],
            'max_uses' => [
                'required',
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
