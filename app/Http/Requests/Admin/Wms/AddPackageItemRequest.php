<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class AddPackageItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'fulfillment_item_id' => ['required', 'integer', 'min:1'],
            'quantity' => ['required', 'numeric', 'gt:0'],
        ];
    }
}
