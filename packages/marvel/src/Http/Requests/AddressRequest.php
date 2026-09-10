<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class AddressRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:255'],
            'address' => ['required', 'array'],
            'address.zip' => ['required', 'string'],
            'address.city' => ['required', 'string'],
            'address.state' => ['required', 'string'],
            'address.country' => ['required', 'string'],
            'address.street_address' => ['required', 'string'],
            'location' => ['sometimes', 'array'],
            'location.latitude' => ['sometimes', 'numeric'],
            'location.longitude' => ['sometimes', 'numeric'],
        ];
    }

    public function failedValidation(Validator $validator)
    {

        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
