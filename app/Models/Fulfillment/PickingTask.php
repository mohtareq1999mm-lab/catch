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
        'order_id',
        'order_item_id',
        'quantity_to_pick',
        'quantity_picked',
        'status',
        'sequence',
        'picked_at',
        'claimed_by',
        'claimed_at',
        'claim_expires_at',
        'op_seq',
        'scan_log',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'quantity_to_pick' => 'decimal:2',
        'quantity_picked' => 'decimal:2',
        'sequence' => 'integer',
        'op_seq' => 'integer',
        'picked_at' => 'datetime',
        'claimed_at' => 'datetime',
        'claim_expires_at' => 'datetime',
        'scan_log' => 'array',
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
