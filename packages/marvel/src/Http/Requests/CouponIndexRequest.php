<?php

namespace Marvel\Http\Requests;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CouponIndexRequest extends FormRequest
{
    public function authorize()
    {
        return true;
    }

    /**
     * Normalize common boolean spellings before validation so admin
     * clients can use ?is_valid=true (the `boolean` rule alone only
     * accepts true/false/1/0/"1"/"0" — anything else still 422s below).
     */
    protected function prepareForValidation()
    {
        foreach ([
            'active', 'inactive', 'is_valid', 'expired',
            'is_public', 'has_assignments', 'has_targeting', 'require_claim',
        ] as $key) {
            if (! $this->has($key)) {
                continue;
            }
            $value = $this->input($key);
            if (is_string($value)) {
                $lower = strtolower($value);
                if ($lower === 'true') {
                    $this->merge([$key => true]);
                } elseif ($lower === 'false') {
                    $this->merge([$key => false]);
                }
            }
        }
    }

    public function rules()
    {
        return [
            // Existing contract (preserved).
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'active' => ['sometimes', 'boolean'],
            'inactive' => ['sometimes', 'boolean'],
            'search' => ['sometimes', 'string', 'max:191'],
            'order' => ['sometimes', 'string', Rule::in([
                'id', 'code', 'name', 'discount', 'discount_type',
                'start_date', 'end_date', 'limiter', 'used',
                'status', 'created_at', 'updated_at',
            ])],
            'sortedBy' => ['sometimes', 'string', Rule::in(['asc', 'desc'])],

            // Validity (mirrors Coupon::scopeValid/scopeInvalid exactly).
            'is_valid' => ['sometimes', 'boolean'],

            // Date ranges (Y-m-d). Null column values never match an
            // explicit field bound; the overlap pair treats nulls as open.
            'start_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'start_date_to' => ['sometimes', 'date_format:Y-m-d'],
            'end_date_from' => ['sometimes', 'date_format:Y-m-d'],
            'end_date_to' => ['sometimes', 'date_format:Y-m-d'],
            'date_from' => ['sometimes', 'date_format:Y-m-d'],
            'date_to' => ['sometimes', 'date_format:Y-m-d'],

            // Discount (persisted values only, never translated labels).
            'discount_type' => ['sometimes', Rule::in(['fixed_rate', 'percentage'])],
            'discount_min' => ['sometimes', 'numeric', 'min:0'],
            'discount_max' => ['sometimes', 'numeric', 'min:0'],
            'max_discount_amount_min' => ['sometimes', 'numeric', 'min:0'],
            'max_discount_amount_max' => ['sometimes', 'numeric', 'min:0'],

            // Usage / limits.
            'limiter_min' => ['sometimes', 'integer', 'min:0'],
            'limiter_max' => ['sometimes', 'integer', 'min:0'],
            'used_min' => ['sometimes', 'integer', 'min:0'],
            'used_max' => ['sometimes', 'integer', 'min:0'],
            // remaining = limiter - used; null limiter counts as +infinity.
            'remaining_min' => ['sometimes', 'integer', 'min:0'],
            'remaining_max' => ['sometimes', 'integer', 'min:0'],

            // Expiry (narrow: past end_date only — NOT the full is_valid).
            'expired' => ['sometimes', 'boolean'],

            // Audience (authoritative resolver semantics; AND-combined).
            'audience_type' => ['sometimes', Rule::in([
                'PUBLIC', 'ASSIGNED', 'TARGETED',
                'PUBLIC_AND_ASSIGNED', 'PUBLIC_AND_TARGETED',
                'ASSIGNED_AND_TARGETED', 'PUBLIC_AND_ASSIGNED_AND_TARGETED',
            ])],
            'is_public' => ['sometimes', 'boolean'],
            'has_assignments' => ['sometimes', 'boolean'],
            'has_targeting' => ['sometimes', 'boolean'],

            // Targeting (eligibility mode only — never publicity).
            'targeting_mode' => ['sometimes', Rule::in([
                'assignment', 'dynamic',
                'assignment_and_dynamic', 'assignment_or_dynamic',
            ])],

            // Assignments (admin-authorized exists-subquery; no PII output).
            'assigned_user_id' => ['sometimes', 'integer', 'min:1'],

            // Claim requirement flag (lives on the targeting row).
            'require_claim' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Contradictory ranges (from > to, min > max) are malformed input,
     * not empty results — reject explicitly per filter contract.
     */
    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $d = $validator->getData();

            foreach ([
                ['start_date_from', 'start_date_to'],
                ['end_date_from', 'end_date_to'],
                ['date_from', 'date_to'],
            ] as [$from, $to]) {
                if (! empty($d[$from]) && ! empty($d[$to]) && $d[$from] > $d[$to]) {
                    $validator->errors()->add($to, "The {$to} must not be before {$from}.");
                }
            }

            foreach ([
                ['discount_min', 'discount_max'],
                ['max_discount_amount_min', 'max_discount_amount_max'],
                ['limiter_min', 'limiter_max'],
                ['used_min', 'used_max'],
                ['remaining_min', 'remaining_max'],
            ] as [$min, $max]) {
                if (isset($d[$min], $d[$max]) && $d[$min] !== '' && $d[$max] !== ''
                    && (float) $d[$min] > (float) $d[$max]) {
                    $validator->errors()->add($max, "The {$max} must not be less than {$min}.");
                }
            }
        });
    }

    public function failedValidation(Validator $validator)
    {
        throw new HttpResponseException(response()->json([
            'message' => __('validation.given_data_invalid'),
            'errors' => $validator->errors(),
        ], 422));
    }
}
