<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePickupLocationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'store_name' => ['required', 'string', 'max:255'],
            'address' => ['required', 'string'],
            'phone' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'latitude' => ['nullable', 'string', 'max:50'],
            'longitude' => ['nullable', 'string', 'max:50'],
            'working_hours' => ['nullable', 'array'],
            'working_hours.*.day' => ['required_with:working_hours', 'array'],
            'working_hours.*.day.*' => ['required_with:working_hours', 'string'],
            'working_hours.*.open' => ['required_with:working_hours', 'string'],
            'working_hours.*.close' => ['required_with:working_hours', 'string'],
            'status' => ['sometimes', 'in:1,0'],
            'display_order' => ['sometimes', 'integer', 'min:0'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
