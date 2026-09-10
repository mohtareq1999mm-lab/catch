<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;

class ShopUpdateRequest extends FormRequest
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
        $id = $this->route('shop');
        return [
        'name'                       => ['sometimes', 'array'],
        'name.*'                     => ['sometimes', 'string', 'max:50',"min:3",UniqueTranslationRule::for('shops')->ignore($id) ],
        'description'                => ['nullable', 'array'],
        'description.*'              => ['nullable', 'string', 'max:2000',"min:3"],
        'logo'                       => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        'cover_image'                => ['sometimes', 'file', 'mimes:jpeg,png,jpg,gif,svg', 'max:2048'],
        'address'                    => ['sometimes', 'array'],
        'address.*.street_address' => ['sometimes', 'array'],
        'address.*.street_address.*' => ['sometimes', 'string', 'max:2000',"min:3"],
        'address.*.city'             => ['sometimes', 'array'],
        'address.*.city.*'             => ['sometimes', 'string', 'max:2000',"min:3"],
        'address.*.state'            => ['sometimes', 'array'],
        'address.*.state.*'            => ['sometimes', 'string', 'max:2000',"min:3"],
        'address.*.country'          => ['sometimes', 'array'],
        'address.*.country.*'          => ['sometimes', 'string', 'max:2000',"min:3"],
        'status'                  => ['nullable', "in:1,0"],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
