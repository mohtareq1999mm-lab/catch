<?php

namespace App\Http\Requests\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * #182: finance-only acceptance input. The actor comes from the authenticated
 * session and is never accepted from the request body. The approval reference
 * doubles as the closure audit reason.
 */
class AcceptCodSettlementStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'reason' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
