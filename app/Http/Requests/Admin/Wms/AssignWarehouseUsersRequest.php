<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class AssignWarehouseUsersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:500'],
            'user_ids.*' => ['integer', 'min:1', 'exists:users,id'],
            'mode' => ['nullable', 'string', 'in:attach,sync,detach'],
        ];
    }
}
