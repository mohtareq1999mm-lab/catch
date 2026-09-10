<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;


class ShopCreateRequest extends FormRequest
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
            'name'                   => ['required', 'array'],
            'name.*'                 => ['required', 'string', 'max:50', "min:3", UniqueTranslationRule::for('shops')],
            'description'            => ['nullable', 'array'],
            'description.*'          => ['nullable', 'string', 'max:2000', "min:3"],
            // 'logo'                   => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            // 'cover_image'            => ['required', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
            'address'                => ['required', 'array'],
            'address.*.street_address' => ['required', 'array'],
            'address.*.street_address.*' => ['required', 'string', 'max:200', "min:3"],
            'address.*.city' => ['required', 'array'],
            'address.*.city.*' => ['required', 'string', 'max:200', "min:3"],
            'address.*.state' => ['required', 'array'],
            'address.*.state.*' => ['required', 'string', 'max:200', "min:3"],
            'address.*.country' => ['required', 'array'],
            'address.*.country.*' => ['required', 'string', 'max:200', "min:3"],
            'status'              => ['required', 'in:1,0'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
