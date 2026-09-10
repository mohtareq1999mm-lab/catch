<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class AddressRequestUpdate extends FormRequest
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
            'title' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'array'],
            'address.zip' => ['sometimes', 'string'],
            'address.city' => ['sometimes', 'string'],
            'address.state' => ['sometimes', 'string'],
            'address.country' => ['sometimes', 'string'],
            'address.street_address' => ['sometimes', 'string'],
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
