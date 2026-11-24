<?php

declare(strict_types=1);

namespace App\Http\Requests\Representative;

use App\Enums\Shipping\DeliveryAttemptStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreDeliveryAttemptRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    protected function prepareForValidation(): void
    {
        $normalized = DeliveryAttemptStatus::normalizeAttempt($this->all());

        $this->merge([
            'status' => $normalized['status'],
            'failure_reason' => $normalized['failure_reason'],
        ]);
    }

    public function rules(): array
    {
        return [
            'status' => ['required', 'string', Rule::in(DeliveryAttemptStatus::values())],
            'failure_reason' => ['nullable', 'string', 'max:2000', 'required_if:status,' . DeliveryAttemptStatus::OTHER->value],
            'attempted_at' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
        ];
    }

    /** @return array<string,string> */
    public function messages(): array
    {
        return [
            'status.in' => __('shipping.delivery_attempt.invalid_status'),
            'failure_reason.required_if' => __('shipping.delivery_attempt.reason_required'),
        ];
    }
}
