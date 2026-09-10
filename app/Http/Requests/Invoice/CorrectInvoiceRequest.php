<?php

namespace App\Http\Requests\Invoice;

use Illuminate\Foundation\Http\FormRequest;

class CorrectInvoiceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => 'required|string|max:500',
            'overrides' => 'nullable|array',
            'overrides.total' => 'nullable|numeric|min:0',
            'overrides.amount_paid' => 'nullable|numeric|min:0',
            'overrides.shipping_price' => 'nullable|numeric|min:0',
            'overrides.customer.name' => 'nullable|string|max:255',
            'overrides.customer.email' => 'nullable|email|max:255',
            'overrides.customer.phone' => 'nullable|string|max:50',
            'overrides.billing_address' => 'nullable|array',
            'overrides.shipping_address' => 'nullable|array',
            'overrides.notes' => 'nullable|string',
        ];
    }
}
