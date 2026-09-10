<?php

namespace App\Http\Requests;

use App\Services\General\ProductEngine\ProductStrategyResolver;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['sometimes', 'nullable', Rule::in(app(ProductStrategyResolver::class)->supportedTypes())],
            'order' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
        ];
    }
}