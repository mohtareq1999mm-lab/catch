<?php

namespace App\Http\Requests\Coupon;

use Illuminate\Foundation\Http\FormRequest;

class UpsertTargetingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'mode' => ['required', 'string', 'in:assignment,dynamic,assignment_and_dynamic,assignment_or_dynamic'],
            'require_claim' => ['required', 'boolean'],
            'max_claims' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'claim_ttl_hours' => ['nullable', 'integer', 'min:1', 'max:8760'],
            'rule_tree' => ['nullable', 'array'],
        ];
    }
}
