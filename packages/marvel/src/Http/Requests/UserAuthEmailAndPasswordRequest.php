<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;


class UserAuthEmailAndPasswordRequest extends FormRequest
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
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'email' => 'required_without:phone_number|email',
            'phone_number' => 'required_without:email|string|max:15|min:8',
            'password' => 'required|string|min:6',
        ];
    }

    public function failedValidation(Validator $validator)
    {

        throw new ValidationException($validator);
    }
}
