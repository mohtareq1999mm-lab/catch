<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class UpdateGatewaySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ONLY the settings.options allowlist is accepted here. Secrets,
     * supported_currencies, methods and class can never arrive via this
     * request — anything else in the payload is dropped by validated().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'enabled' => ['sometimes', 'boolean'],
            'display_name' => ['sometimes', 'nullable', 'string', 'max:60'],
            'sort_order' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:999999'],
        ];
    }
}
