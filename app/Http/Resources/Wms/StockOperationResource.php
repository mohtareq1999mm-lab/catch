<?php

namespace App\Http\Resources\Wms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StockOperationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $result = is_array($this->resource) ? $this->resource : [];

        return [
            'product_id' => $result['product_id'] ?? null,
            'product_variant_id' => $result['product_variant_id'] ?? null,
            'sku' => $result['sku'] ?? null,
            'warehouse_id' => $result['warehouse_id'] ?? null,
            'stock_quantity' => isset($result['stock_quantity']) ? (int) $result['stock_quantity'] : null,
            'reserved_quantity' => isset($result['reserved_quantity']) ? (int) $result['reserved_quantity'] : null,
            'available_quantity' => $result['available_quantity'] ?? null,
            'in_stock' => $result['in_stock'] ?? null,
            'received_quantity' => $result['received_quantity'] ?? null,
            'transferred_quantity' => $result['transferred_quantity'] ?? null,
            'placement' => $result['placement'] ?? null,
            'source_placement' => $result['source_placement'] ?? null,
            'destination_placement' => $result['destination_placement'] ?? null,
            'reference' => $result['reference'] ?? null,
        ];
    }
}
