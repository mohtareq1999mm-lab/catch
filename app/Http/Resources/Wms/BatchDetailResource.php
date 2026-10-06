<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * P9-4.1: bounded batch detail. Tasks eager-loaded by the controller;
 * fulfillment references derived from the loaded tasks (no extra queries).
 * No `allowed_actions` (P9-9 owns that).
 */
class BatchDetailResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return array_merge((new BatchResource($this->resource))->toArray($request), [
            'tasks' => PickingTaskResource::collection($this->whenLoaded('pickingTasks')),
            'notes' => $this->notes,
        ]);
    }
}
