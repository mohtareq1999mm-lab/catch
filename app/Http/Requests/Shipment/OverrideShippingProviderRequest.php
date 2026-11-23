<?php

declare(strict_types=1);

namespace App\Http\Requests\Shipment;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * #148: the override body carries intent and accountability only. The value
 * must match a deployment-configured provider; it is never an adapter class,
 * credential, URL, or an arbitrary carrier identifier.
 */
final class OverrideShippingProviderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'provider' => [
                'required',
                'string',
                'max:50',
                Rule::in(array_map('strval', array_keys((array) config('shipping.providers', [])))),
            ],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }
}
