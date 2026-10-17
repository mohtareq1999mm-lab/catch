<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class CheckLocationOccupancyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'location_ids' => ['required_without:locations', 'array', 'min:1'],
            'location_ids.*' => ['integer'],
            'locations' => ['required_without:location_ids', 'array', 'min:1'],
            'locations.*' => ['integer'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
        ];
    }

    /**
     * @return array<int>
     */
    public function locationIds(): array
    {
        $raw = $this->input('location_ids', $this->input('locations', []));

        return array_values(array_unique(array_filter(array_map('intval', (array) $raw))));
    }
}
