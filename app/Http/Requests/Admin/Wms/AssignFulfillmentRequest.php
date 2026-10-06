<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class AssignFulfillmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The assignee — never the actor. Actor identity always comes
            // from the authenticated context (FulfillmentController).
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
