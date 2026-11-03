<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;


class BecameSellersRequest extends FormRequest
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
     * Normalize the locale before validation so `EN`/` ar ` map to the same
     * supported locale instead of being rejected as unknown values.
     */
    protected function prepareForValidation(): void
    {
        if (is_string($this->language)) {
            $this->merge(['language' => strtolower(trim($this->language))]);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        $supportedLocales = (array) config('app.supported_locales', ['en', 'ar']);

        return [
            // `language` is written to became_sellers/commissions and keys the
            // public page cache; arbitrary values would create unreachable rows
            // and permanently stale cache keys.
            'language' => ['nullable', 'string', 'max:10', Rule::in($supportedLocales)],
            'page_options' => ['required', 'array'],
            'commission.*.min_balance' => ['required','numeric', 'min:0'],
            'commission.*.max_balance' => ['required'],
            'commission.*.commission' => ['required','numeric', 'min:0'],
            'commission.*.level' => ['required','string'],
            'commission.*.sub_level' => ['required','string'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
