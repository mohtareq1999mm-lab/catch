<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class AssignPackingTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The assignee is always the authenticated caller (actor forgery
     * impossible: no user_id field). The station must belong to the
     * fulfillment's warehouse — enforced by PackingService (422).
     */
    public function rules(): array
    {
        return [
            'station_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
