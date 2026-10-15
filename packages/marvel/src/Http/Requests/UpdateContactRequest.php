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
     * (phone_number, otp_id, code; legacy `user_id` is accepted but ignored —
     * SEC-002 self-only: the authenticated caller's id is authoritative) so
     * failures surface through the canonical Handler 422 envelope.
     *
     * @return array
     */
    public function rules()
    {
        return [
            // Ignored by the controller (SEC-002 self-only); validated only as
            // an integer when present so stale/foreign ids never 422 the caller.
            'user_id' => ['nullable', 'integer'],
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
