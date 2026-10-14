<?php

namespace App\Models\Inventory;

use Illuminate\Database\Eloquent\Model;

class InventoryOperation extends Model
{
    public const OPERATION_RECEIPT = 'receipt';

    public const OPERATION_TRANSFER = 'transfer';

    public const OPERATION_INITIAL_PLACEMENT = 'initial_placement';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'idempotency_key',
        'operation',
        'product_id',
        'product_variant_id',
        'warehouse_id',
        'source_location_id',
        'destination_location_id',
        'quantity',
        'reference',
        'status',
        'result',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'result' => 'array',
    ];

    public function product(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\Product::class, 'product_id');
    }

    public function productVariant(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\ProductVariant::class, 'product_variant_id');
    }

    public function warehouse(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Fulfillment\Warehouse::class, 'warehouse_id');
    }

    public function sourceLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Fulfillment\Location::class, 'source_location_id');
    }

    public function destinationLocation(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(\App\Models\Fulfillment\Location::class, 'destination_location_id');
    }
}
