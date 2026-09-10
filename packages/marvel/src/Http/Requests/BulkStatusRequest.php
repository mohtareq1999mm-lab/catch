<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $table = $this->resolveTable();

        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'min:1', 'distinct', "exists:{$table},id"],
            'status' => ['required', 'in:0,1'],
        ];
    }

    private function resolveTable(): string
    {
        $path = $this->path();

        if (str_contains($path, 'governorates')) {
            return 'governorates';
        }

        if (str_contains($path, 'shipping-prices')) {
            return 'shipping_prices';
        }

        return 'countries';
    }
    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}