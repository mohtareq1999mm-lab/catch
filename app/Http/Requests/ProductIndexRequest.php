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
            'pagination' => ['sometimes', 'nullable', Rule::in(['offset', 'cursor'])],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($this->query('pagination') !== 'cursor') {
                return;
            }

            if (!config('cursor.enabled', false)) {
                $validator->errors()->add('pagination', __('validation.custom.pagination.disabled'));

                return;
            }

            if (trim((string) $this->query('search', '')) !== '') {
                $validator->errors()->add('pagination', __('validation.custom.pagination.search'));
            }

            // order_price is now supported with cursor pagination
            // Removed the restriction that previously returned 422
        });
    }
}