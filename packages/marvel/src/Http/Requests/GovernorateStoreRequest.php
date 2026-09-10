<?php

namespace Marvel\Http\Requests;

use CodeZero\UniqueTranslation\UniqueTranslationRule;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class GovernorateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'country_id' => ['required', 'integer', 'exists:countries,id'],
            'name' => ['required', 'array'],
            'name.en' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('governorates')],
            'name.ar' => ['required', 'string', 'min:2', 'max:50', UniqueTranslationRule::for('governorates')],
            'status' => ['nullable', 'in:1,0'],
            'is_fast_shipping_enabled' => ['nullable', 'boolean'],
            'shipping_price' => ['sometimes', 'array'],
            'shipping_price.price' => ['sometimes', 'numeric'],
            'shipping_price.estimated_days' => ['sometimes', 'integer'],
            'shipping_price.free_shipping_over' => ['sometimes', 'numeric'],
            'shipping_price.status' => ['sometimes', 'boolean'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
