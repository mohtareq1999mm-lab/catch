<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class MeemProductCreateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'image_url' => ['nullable', 'url'],
            'price' => ['nullable', 'numeric', 'min:0'],
            'url' => ['nullable', 'url'],
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
            'name.required' => 'Name field is required',
            'name.string' => 'Name is not a valid string',
            'name.max' => 'Name cannot exceed 255 characters',
            'price.numeric' => 'Price must be a number',
            'price.min' => 'Price cannot be negative',
            'image_url.url' => 'Image URL must be a valid URL',
            'url.url' => 'URL must be a valid URL',
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
