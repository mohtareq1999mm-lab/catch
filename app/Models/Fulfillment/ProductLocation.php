<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

class ProductLocation extends Model
{
    protected $fillable = [
        'product_id',
        'product_variant_id',
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

    /**
     * SKU-level identity: NULL = simple product row, NOT NULL = variant row.
     * Authority mirrors OrderReservationService::lockStockRow.
     */
    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id');
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

    public function scopeForSku($query, int $productId, ?int $variantId)
    {
        // S4: explicit null check (IDs are never 0, but !== null is correct).
        return $variantId !== null
            ? $query->where('product_id', $productId)->where('product_variant_id', $variantId)
            : $query->where('product_id', $productId)->whereNull('product_variant_id');
    }
}
