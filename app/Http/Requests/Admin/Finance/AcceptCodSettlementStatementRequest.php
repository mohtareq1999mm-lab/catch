<?php

namespace App\Http\Requests\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * #182: finance-only acceptance input. The actor comes from the authenticated
 * session and is never accepted from the request body.
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
            'supersedes_statement_id' => [
                'sometimes',
                'integer',
                'min:1',
                Rule::exists('cod_settlement_statements', 'id'),
            ],
        ];
    }
}
