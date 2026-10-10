<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;


class UserCreateRequest extends FormRequest
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
            'first_name'     => ['required', 'string', 'max:50', 'min:2'],
            'last_name'     => ['required', 'string', 'max:50', "min:2"],
            'email'    => ['nullable', 'sometimes', 'email', 'unique:users,email', 'email:rfc,dns'],
            'phone_number'    => ['required', 'string', 'max:20', "min:10", 'unique:users,phone_number'],
            'password' => ['required', 'string', 'min:8', 'max:50', 'confirmed'],
            'password_confirmation' => ['required', 'string', 'min:8', 'max:50'],
             'avatar' => ['sometimes', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'policy' => ['required', 'in:1,true'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}