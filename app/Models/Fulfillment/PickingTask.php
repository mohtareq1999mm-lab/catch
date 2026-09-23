<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PickingTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'batch_id',
        'fulfillment_item_id',
        'product_location_id',
        'quantity_to_pick',
        'quantity_picked',
        'status',
        'sequence',
        'picked_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity_to_pick' => 'decimal:2',
        'quantity_picked' => 'decimal:2',
        'sequence' => 'integer',
        'picked_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function batch(): BelongsTo
    {
        return $this->belongsTo(FulfillmentBatch::class, 'batch_id');
    }

    public function fulfillmentItem(): BelongsTo
    {
        return $this->belongsTo(FulfillmentItem::class);
    }

    public function productLocation(): BelongsTo
    {
        return $this->belongsTo(ProductLocation::class);
    }

    public function remainingQuantity(): float
    {
        return $this->quantity_to_pick - $this->quantity_picked;
    }

    public function isComplete(): bool
    {
        return $this->quantity_picked >= $this->quantity_to_pick;
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

    public function scopeBySequence($query)
    {
        return $query->orderBy('sequence', 'asc');
    }
}
