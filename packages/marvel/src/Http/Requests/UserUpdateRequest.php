<?php


namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;


class UserUpdateRequest extends FormRequest
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
            'name'    => ['string', 'max:255'],
            'email'   => ['email', 'unique:users,email,' . $this->route('id')],
            'avatar' => ['nullable', 'image', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'profile' => ['array'],
            'address' => ['array'],
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
            'name.string'        => 'Name is not a valid string',
            'name.max:255'       => 'Name can not be more than 255 character',
            'email.email'        => 'email is not a valid email address',
            'email.unique:users' => 'email must be unique',
            'image.image'        => 'image is not a valid image',
            'address.array'      => 'address is not a valid json',
            'profile.array'      => 'profile is not a valid json',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
