<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class FulfillmentBatch extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'batch_number',
        'status',
        'type',
        'assigned_to',
        'started_at',
        'completed_at',
        'cancelled_at',
        'total_items',
        'picked_items',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'total_items' => 'integer',
        'picked_items' => 'integer',
        'metadata' => 'array',
    ];

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function pickingTasks(): HasMany
    {
        return $this->hasMany(PickingTask::class, 'batch_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAssigned($query)
    {
        return $query->where('status', 'assigned');
    }

    public function scopePicking($query)
    {
        return $query->where('status', 'picking');
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    public function isComplete(): bool
    {
        return $this->picked_items >= $this->total_items && $this->total_items > 0;
    }

    public function progressPercentage(): float
    {
        if ($this->total_items === 0) {
            return 0;
        }

        return round(($this->picked_items / $this->total_items) * 100, 2);
    }
}
