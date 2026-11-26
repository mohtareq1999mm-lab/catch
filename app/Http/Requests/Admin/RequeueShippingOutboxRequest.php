<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class RequeueShippingOutboxRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization is enforced by the admin route middleware + controller.
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'max:400'],
        ];
    }
}
