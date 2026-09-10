<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;


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

    /**
     * Get the error messages that apply to the request parameters.
     *
     * @return array
     */
    public function messages()
    {
        return [
            'name.required'      => 'Name is required',
            'name.string'        => 'Name is not a valid string',
            'name.max:255'       => 'Name can not be more than 255 character',
            'email.required'     => 'email is required',
            'email.email'        => 'email is not a valid email address',
            'email.unique:users' => 'email must be unique',
            'password.required'  => 'password is required',
            'password.string'    => 'password is not a valid string',
            'address.array'      => 'address is not a valid json',
            'profile.array'      => 'profile is not a valid json',
            'phone_number.required' => 'phone number is required',
            'phone_number.unique:users' => 'phone number already taken',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}