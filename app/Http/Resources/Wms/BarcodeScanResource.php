<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BarcodeScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'kind' => $this->resource['kind'] ?? null,
            'detected_by' => $this->resource['detected_by'] ?? null,
            'data' => $this->resource['data'] ?? [],
        ];
    }
}
