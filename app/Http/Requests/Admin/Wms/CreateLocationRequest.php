<?php

namespace App\Http\Requests\Admin\Wms;

use App\Models\Fulfillment\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'warehouse_id' => ['required', 'integer', 'exists:warehouses,id'],
            'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
            'code' => [
                'required', 'string', 'max:50',
                Rule::unique('locations', 'code')->where(
                    fn ($query) => $query->where('warehouse_id', (int) $this->input('warehouse_id'))
                ),
            ],
            'name' => ['required', 'string', 'max:255'],
            'barcode' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'in:' . implode(',', array_merge(
                Location::PLACEABLE_TYPES,
                [Location::TYPE_QUARANTINE, Location::TYPE_DAMAGED, Location::TYPE_RETURNS]
            ))],
            'status' => ['sometimes', 'in:' . Location::STATUS_ACTIVE . ',' . Location::STATUS_INACTIVE],
            'priority' => ['sometimes', 'integer'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
