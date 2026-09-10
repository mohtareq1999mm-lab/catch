<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Marvel\Database\Models\Order;

class OrderStatusUpdateRequest extends FormRequest
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
            'status' => [
                'required',
                'string',
                Rule::in([
                    Order::ORDER_STATUS_PENDING,
                    Order::ORDER_STATUS_PROCESSING,
                    Order::ORDER_STATUS_COMPLETED,
                    Order::ORDER_STATUS_DELIVERED,
                    Order::ORDER_STATUS_CANCELLED,
                ]),
            ],
        ];
    }
}