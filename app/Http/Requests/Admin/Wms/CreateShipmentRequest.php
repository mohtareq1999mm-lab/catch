<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class CreateShipmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only safe label fields are accepted: status/tracking/ids are owned by
     * the service. The idempotency key scopes to (fulfillment, key) — same
     * key + same fulfillment replays the same row, same key + different
     * fulfillment is refused loudly by the service.
     */
    public function rules(): array
    {
        return [
            'courier' => ['nullable', 'string', 'max:255'],
            'shipping_method' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'destination_address' => ['nullable', 'array'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
        ];
    }
}
