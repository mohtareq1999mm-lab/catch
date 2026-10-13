<?php

namespace App\Http\Requests\Admin\Wms;

use App\Models\Fulfillment\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateLocationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Non-warehouse fields only. warehouse_id is immutable (model guard
     * D-LOC-MOVE); status moves through activate/deactivate commands.
     */
    public function rules(): array
    {
        $locationId = (int) $this->route('id');

        return [
            'parent_id' => ['nullable', 'integer', 'exists:locations,id'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Same DB-unique scan identity as create: ignore self so an
            // unchanged barcode passes while a foreign duplicate 422s.
            'barcode' => ['nullable', 'string', 'max:255', Rule::unique('locations', 'barcode')->ignore($locationId)],
            'type' => ['nullable', 'in:' . implode(',', array_merge(
                Location::PLACEABLE_TYPES,
                [Location::TYPE_QUARANTINE, Location::TYPE_DAMAGED, Location::TYPE_RETURNS]
            ))],
            'priority' => ['sometimes', 'integer'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
