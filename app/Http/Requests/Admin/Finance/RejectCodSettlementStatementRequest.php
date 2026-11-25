<?php

namespace App\Http\Requests\Admin\Finance;

use Illuminate\Foundation\Http\FormRequest;

/**
 * #182: finance-only rejection input. No business state is supplied by the
 * caller; the actor is resolved from the authenticated session.
 */
class RejectCodSettlementStatementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
