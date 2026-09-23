<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Marvel\Database\Models\Order;
use App\Models\User;

class Fulfillment extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_id',
        'warehouse_id',
        'fulfillment_number',
        'idempotency_key',
        'status',
        'priority',
        'assigned_to',
        'picking_started_at',
        'picking_completed_at',
        'packing_started_at',
        'packing_completed_at',
        'ready_to_ship_at',
        'shipped_at',
        'delivered_at',
        'cancelled_at',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'picking_started_at' => 'datetime',
        'picking_completed_at' => 'datetime',
        'packing_started_at' => 'datetime',
        'packing_completed_at' => 'datetime',
        'ready_to_ship_at' => 'datetime',
        'shipped_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function items(): HasMany
    {
        return $this->hasMany(FulfillmentItem::class);
    }

    public function packingTasks(): HasMany
    {
        return $this->hasMany(PackingTask::class);
    }

    public function shipments(): HasMany
    {
        return $this->hasMany(\App\Models\Shipment::class, 'fulfillment_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePicking($query)
    {
        return $query->where('status', 'picking');
    }

    public function scopePacking($query)
    {
        return $query->where('status', 'packing');
    }

    public function scopeByPriority($query)
    {
        return $query->orderByRaw("FIELD(priority, 'high', 'normal', 'low')");
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function scopeAssignedTo($query, int $userId)
    {
        return $query->where('assigned_to', $userId);
    }

    /**
     * Phase 6: authoritative fulfillment DAG (model-owned, Shipment pattern).
     * Locked states: pending → picking → picked → packing → ready_to_ship
     * → shipped → delivered, with supervised exits to cancelled.
     * Fulfillment-level `packed` was a ghost state (task-level only) and is
     * intentionally absent: packing completion keeps `packing` until verified.
     */
    public static function allowedTransitions(string $from): array
    {
        return match ($from) {
            'pending' => ['picking', 'cancelled'],
            'picking' => ['picked', 'packing', 'cancelled'],
            'picked' => ['packing', 'cancelled'],
            'packing' => ['ready_to_ship', 'cancelled'],
            'ready_to_ship' => ['shipped', 'cancelled'],
            'shipped' => ['delivered'],
            'delivered' => [],
            'cancelled' => [],
            default => [],
        };
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::allowedTransitions($this->status ?? 'pending'), true);
    }
}
