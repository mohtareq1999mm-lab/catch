<?php

declare(strict_types=1);

namespace App\Http\Requests\Payment;

use Illuminate\Foundation\Http\FormRequest;

class RefundOrderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * ONLY the refund allowlist is accepted here. Anything else in the
     * payload is dropped by validated().
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:0.01', 'max:99999999.999'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:191'],
        ];
    }
}
