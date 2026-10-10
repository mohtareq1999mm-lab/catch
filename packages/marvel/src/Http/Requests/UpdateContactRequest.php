<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class UpdateContactRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Mirror the raw fields UserController@updateContact reads
     * (user_id, phone_number, otp_id, code) so failures surface
     * through the canonical Handler 422 envelope.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'phone_number' => ['required', 'string', 'min:8', 'max:20'],
            'otp_id' => ['required', 'string'],
            'code' => ['required', 'string', 'min:4', 'max:6'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
