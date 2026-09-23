<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\OrderProduct;

class ReturnItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'return_request_id',
        'order_item_id',
        'product_id',
        'fulfillment_item_id',
        'quantity_returned',
        'quantity_approved',
        'quantity_restocked',
        'condition',
        'inspection_notes',
        'restocked_location_id',
        'product_location_id',
        'inspected_at',
        'restocked_at',
        'metadata',
    ];

    protected $casts = [
        'inspected_at' => 'datetime',
        'restocked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function returnRequest(): BelongsTo
    {
        return $this->belongsTo(ReturnRequest::class);
    }

    public function orderItem(): BelongsTo
    {
        return $this->belongsTo(OrderProduct::class, 'order_item_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function fulfillmentItem(): BelongsTo
    {
        return $this->belongsTo(FulfillmentItem::class);
    }

    public function restockedLocation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'restocked_location_id');
    }

    public function productLocation(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class);
    }

    public function scopeGoodCondition($query)
    {
        return $query->where('condition', 'good');
    }

    public function scopeDamaged($query)
    {
        return $query->where('condition', 'damaged');
    }

    public function scopeDefective($query)
    {
        return $query->where('condition', 'defective');
    }

    public function scopeWrongItem($query)
    {
        return $query->where('condition', 'wrong_item');
    }

    public function isRestockable(): bool
    {
        return in_array($this->condition, ['good', 'wrong_item']);
    }

    public function isFullyRestocked(): bool
    {
        return $this->quantity_restocked >= $this->quantity_approved;
    }
}
