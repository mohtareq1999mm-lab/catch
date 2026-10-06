<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class AssignBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // The assignee — never the actor (always auth()->id()).
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ];
    }
}
