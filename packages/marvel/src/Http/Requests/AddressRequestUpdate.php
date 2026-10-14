<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;


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
            // AREA_IN saved-address remediation: optional canonical link.
            'governorate_id' => ['nullable', 'integer', 'exists:governorates,id'],
        ];
    }

    /**
     * Friendly names for nested fields. Only keys with an existing
     * validation.attributes.* entry in both locales are mapped;
     * simple fields are covered by the global attributes.
     *
     * @return array
     */
    public function attributes()
    {
        return [
            'address.city' => __('validation.attributes.city'),
            'address.country' => __('validation.attributes.country'),
            'governorate_id' => __('validation.attributes.governorate_id'),
        ];
    }

    public function failedValidation(Validator $validator)
    {

        throw new ValidationException($validator);
    }
}
