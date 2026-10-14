<?php

namespace Marvel\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\ValidationException;

class CheckoutVerifyRequest extends FormRequest
{
    protected $rules = [];

    /**
     * General validation rules
     *
     * @return array
     */
    protected function getRules()
    {
        return [
            'amount'           => 'required|numeric',
            'customer_id'      => 'nullable|exists:Marvel\Database\Models\User,id',
            'products'         => 'required|array',
            'billing_address'  => 'array',
            'shipping_address' => 'array',
        ];
    }

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
        return $this->getRules();
    }

    public function failedValidation(Validator $validator)
    {
        throw new ValidationException($validator);
    }
}
