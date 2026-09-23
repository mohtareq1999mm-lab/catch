<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\ProductVariant;

class FulfillmentItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'fulfillment_id',
        'order_item_id',
        'product_id',
        'product_variant_id',
        'product_location_id',
        'quantity',
        'quantity_picked',
        'status',
        'picked_at',
        'packed_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'quantity_picked' => 'decimal:2',
        'picked_at' => 'datetime',
        'packed_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\OrderProduct::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productVariant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class);
    }

    public function productLocation(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class);
    }

    public function remainingQuantity(): float
    {
        return $this->quantity - $this->quantity_picked;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePicking($query)
    {
        return $query->where('status', 'picking');
    }

    public function scopePicked($query)
    {
        return $query->where('status', 'picked');
    }

    public function scopePacked($query)
    {
        return $query->where('status', 'packed');
    }
}
