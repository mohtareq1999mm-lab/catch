<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class AdminCreateUserRequest extends FormRequest
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
            'roles' => 'sometimes|array',
            'roles.*' => 'integer|exists:roles,id',
            "name" => "required",
            "email" => "required|email|unique:users,email",
            "password" => "required|min:6|confirmed|max:50",
            "phone_number" => "required|unique:users,phone_number",
            'image' => 'nullable|image|mimes:jpeg,png,jpg,webp,gif',
            'is_active' => 'nullable|in:0,1',
        ];
    }

    public function failedValidation(Validator $validator)
    {

        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
