<?php

namespace App\Http\Requests\Admin;

use App\Services\General\OrderStatusBatchService;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Unified Order Status Mutation contract: ONE endpoint for ONE or MANY orders.
 *
 * The order count is determined entirely by `order_ids`; the pipeline is
 * identical for a single id and for a batch. Existence is deliberately NOT
 * validated here — unknown ids surface as per-order `order_not_found`
 * results so one bad id never aborts the batch.
 */
class OrderStatusBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'order_ids' => ['required', 'array', 'min:1', 'max:' . OrderStatusBatchService::MAX_BATCH],
            'order_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
            // The global Order Status Catalog remains authoritative — the
            // service layer enforces which codes each order's flow permits.
            'status' => ['required', 'string', Rule::in(OrderFlowService::ALL_STATUS_CODES)],
            // Common transition inputs, applied to EVERY order independently
            // (e.g. one customs_reference shared by the batch). Per-order
            // values are a documented future extension, not this contract.
            'flow_values' => ['nullable', 'array'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json($validator->errors(), 422));
    }
}
