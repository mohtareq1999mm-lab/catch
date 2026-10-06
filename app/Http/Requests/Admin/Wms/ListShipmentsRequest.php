<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListShipmentsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * fulfillment_id is required: only fulfillment-linked shipments belong
     * to the WMS surface (legacy order-only labels stay on the order
     * shipment surface). Scope resolves via the fulfillment's warehouse.
     */
    public function rules(): array
    {
        return [
            'fulfillment_id' => ['required', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'max:50'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
