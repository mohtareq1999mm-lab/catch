<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BrandsReorderRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    public function rules()
    {
        return [
            'brands' => ['required', 'array'],
            'brands.*' => ['required', 'integer', 'exists:brands,id'],
        ];
    }

    public function messages()
    {
        return [
            'brands.required' => 'Brands list is required',
            'brands.array' => 'Brands must be an array',
            'brands.*.required' => 'Each brand ID is required',
            'brands.*.integer' => 'Each brand ID must be an integer',
            'brands.*.exists' => 'One or more brands do not exist',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
