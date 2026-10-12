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
}
