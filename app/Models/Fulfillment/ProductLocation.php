<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Product;

class ProductLocation extends Model
{
    protected $fillable = [
        'product_id',
        'location_id',
        'warehouse_id',
        'quantity',
        // Phase 7: non-authoritative placement hint (renamed from
        // reserved_quantity). NEVER decides sellable inventory.
        'allocated_hint',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'allocated_hint' => 'decimal:2',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(Location::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function availableQuantity(): float
    {
        return $this->quantity - $this->allocated_hint;
    }

    public function scopeHasStock($query)
    {
        return $query->where('quantity', '>', 0);
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }
}
