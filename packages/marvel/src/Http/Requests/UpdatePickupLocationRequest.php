<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class UpdatePickupLocationRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'store_name' => ['sometimes', 'string', 'max:255'],
            'address' => ['sometimes', 'string'],
            'phone' => ['sometimes', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'latitude' => ['nullable', 'string', 'max:50'],
            'longitude' => ['nullable', 'string', 'max:50'],
            'working_hours' => ['nullable', 'array'],
            'working_hours.*.day.ar' => ['required_with:working_hours', 'string'],
            'working_hours.*.day.en' => ['required_with:working_hours', 'string'],
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