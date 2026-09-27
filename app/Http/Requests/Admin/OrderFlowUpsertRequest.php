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
            'name' => ['sometimes', 'required', 'string', 'max:100'],
            'shipping_type' => [
                'sometimes',
                'required',
                'string',
                'max:30',
                Rule::in(OrderFlowService::SUPPORTED_SHIPPING_TYPES),
                // One active flow per shipping_type: the type IS the mapping.
                Rule::unique('order_flows', 'shipping_type')->ignore($flowId),
            ],
            'is_default' => ['sometimes', 'required', 'boolean'],
            'is_active' => ['sometimes', 'required', 'boolean'],
            // Array ORDER defines sort_order; From/To is derived, never input.
            'status_ids' => ['sometimes', 'required', 'array', 'min:1'],
            'status_ids.*' => ['integer', 'exists:order_statuses,id'],
        ];
    }
}
