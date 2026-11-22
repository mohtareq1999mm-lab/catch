<?php

namespace App\Http\Requests\Admin\Finance;

use App\Enums\SettlementMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * #135: read-only filter validation for COD settlement evidence.
 * Authorization is enforced by the route/controller permission middleware.
 */
class ListCodSettlementStatementsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'provider_code' => ['sometimes', 'string', 'max:32'],
            'mode' => ['sometimes', Rule::in(array_map(
                fn (SettlementMode $mode): string => $mode->value,
                SettlementMode::cases()
            ))],
            'account_code' => ['sometimes', 'string', 'max:64'],
            'currency_code' => ['sometimes', 'string', 'size:3'],
            'period_start' => ['sometimes', 'date_format:Y-m-d'],
            'period_end' => ['sometimes', 'date_format:Y-m-d'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
