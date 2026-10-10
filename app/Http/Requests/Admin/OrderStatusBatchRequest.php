<?php

namespace App\Http\Requests\Admin;

use App\Services\General\OrderStatusBatchService;
use App\Services\OrderFlow\OrderFlowService;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
            // (e.g. one customs_reference shared by the batch).
            'flow_values' => ['nullable', 'array'],
            // Per-order transition inputs (D8a): merged OVER the common
            // flow_values for that order only, then validated against that
            // order's own flow — one order's missing/invalid input fails
            // only that order (partial success preserved). Keys must name
            // orders in this batch; anything else is a request-shape 422.
            'flow_values_by_order' => ['nullable', 'array'],
            'flow_values_by_order.*' => ['array'],
        ];
    }

    /**
     * Keys of flow_values_by_order must reference orders in this batch.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $byOrder = $this->input('flow_values_by_order');

            if (!is_array($byOrder)) {
                return;
            }

            $ids = array_map('intval', (array) $this->input('order_ids', []));

            foreach (array_keys($byOrder) as $key) {
                if (!in_array((int) $key, $ids, true)) {
                    $validator->errors()->add(
                        'flow_values_by_order',
                        __('checkout.flow_values_unknown_order', ['id' => $key])
                    );

                    return;
                }
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
