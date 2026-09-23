<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\User;
use Marvel\Database\Models\Order;

class ReturnRequest extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        'return_number',
        'order_id',
        'fulfillment_id',
        'warehouse_id',
        'customer_id',
        'status',
        'reason',
        'customer_notes',
        'admin_notes',
        'requested_by',
        'approved_by',
        'inspected_by',
        'requested_at',
        'approved_at',
        'rejected_at',
        'received_at',
        'inspected_at',
        'restocked_at',
        'completed_at',
        'images',
        'metadata',
    ];

    protected $casts = [
        'requested_at' => 'datetime',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
        'received_at' => 'datetime',
        'inspected_at' => 'datetime',
        'restocked_at' => 'datetime',
        'completed_at' => 'datetime',
        'images' => 'array',
        'metadata' => 'array',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function inspectedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'inspected_by');
    }

    public function returnItems(): HasMany
    {
        return $this->hasMany(ReturnItem::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeApproved($query)
    {
        return $query->where('status', 'approved');
    }

    public function scopeReceived($query)
    {
        return $query->where('status', 'received');
    }

    public function scopeInspecting($query)
    {
        return $query->where('status', 'inspecting');
    }

    public function scopeRestocked($query)
    {
        return $query->where('status', 'restocked');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }

    public function isComplete(): bool
    {
        return in_array($this->status, ['completed', 'cancelled']);
    }

    public function canBeApproved(): bool
    {
        return $this->status === 'pending';
    }

    public function canBeReceived(): bool
    {
        return $this->status === 'approved';
    }

    public function canBeInspected(): bool
    {
        return $this->status === 'received';
    }

    public function canBeRestocked(): bool
    {
        return $this->status === 'inspecting';
    }
}
