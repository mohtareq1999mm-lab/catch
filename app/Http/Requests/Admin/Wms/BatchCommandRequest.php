<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class BatchCommandRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reason-carrying batch commands (cancel, retry). Assignment carries
     * user_id instead — see AssignBatchRequest.
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
