<?php

namespace Marvel\Http\Requests;

use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;


class UpdateTermsAndConditionsRequest extends FormRequest
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
        // $language = $this->language ?? DEFAULT_LANGUAGE;

        $rules =  [
            'title'       => ['required', 'string'],
            'description' => ['required', 'string', 'max:10000'],
            'slug'        => ['nullable', 'string'],
            'language'    => ['nullable', 'string'],
        ];
        return $rules;
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
