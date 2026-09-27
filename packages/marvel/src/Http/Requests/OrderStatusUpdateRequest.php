<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
        // Legacy 5 plus catalog logistics codes; the service layer enforces
        // which of these the order's flow actually permits next.
        return [
            'status' => [
                'required',
                'string',
                Rule::in(\App\Services\OrderFlow\OrderFlowService::ALL_STATUS_CODES),
            ],
        ];
    }
}