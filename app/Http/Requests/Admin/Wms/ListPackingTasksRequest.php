<?php

namespace App\Http\Requests\Admin\Wms;

use Illuminate\Foundation\Http\FormRequest;

class ListPackingTasksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Packing-task list filters. station_id and fulfillment_id are scoped
     * in-controller (station → its warehouse, task → fulfillment warehouse).
     */
    public function rules(): array
    {
        return [
            'warehouse_id' => ['nullable', 'integer', 'min:1'],
            'station_id' => ['nullable', 'integer', 'min:1'],
            'fulfillment_id' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'string', 'in:pending,assigned,packing,packed,verified,cancelled'],
            'assigned_to' => ['nullable', 'integer', 'min:1'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
