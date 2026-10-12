<?php

namespace App\Http\Requests\Admin;

use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrderFlowUpsertRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Legacy string name -> {en} so the model always stores bilingual JSON.
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => ['en' => $this->input('name')]]);
        }
    }

    public function rules(): array
    {
        $flowId = $this->route('id') ?? $this->route('flow');

        return [
            'code' => [
                'sometimes',
                'required',
                'string',
                'max:50',
                Rule::unique('order_flows', 'code')->ignore($flowId),
            ],
            'name' => ['sometimes', 'required'],
            'name.en' => ['sometimes', 'required', 'string', 'max:100'],
            'name.ar' => ['nullable', 'string', 'max:100'],
            'shipping_type' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                // Controlled business discriminator: exactly local|international.
                // Multi-flow (2026_10_12): the type is a SHARED category, not a
                // unique identity — uniqueness lives on `code`. Duplicate types
                // across flows are allowed by design.
                Rule::in(OrderFlowService::SUPPORTED_SHIPPING_TYPES),
            ],
            'is_default' => ['sometimes', 'required', 'boolean'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            // Array ORDER defines sort_order; From/To is derived, never input.
            'status_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'status_ids.*' => ['integer', 'exists:order_statuses,id'],
        ];
    }
}
