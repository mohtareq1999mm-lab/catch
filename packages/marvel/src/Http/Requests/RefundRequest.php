<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;


class RefundRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     *
     * @return bool
     */
    public function authorize()
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array
     */
    public function rules()
    {
        return [
            'order_id' => ['required', 'exists:orders,id'],
            // Phase 10 unification: the amount is client-proposed and
            // server-validated (capped at remaining refundable); currency
            // must match the order currency when provided.
            'amount' => ['required', 'numeric', 'min:0.01'],
            'currency' => ['nullable', 'string', 'size:3'],
            'title' => ['nullable', 'string', 'max:191'],
            'description' => ['string', 'nullable', 'max:10000'],
            'images' => ['array', 'nullable'],
            'refund_reason_id' => ['nullable', 'exists:refund_reasons,id'],
        ];
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
