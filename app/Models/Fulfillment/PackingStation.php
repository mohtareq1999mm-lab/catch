<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Models\User;

class PackingStation extends Model
{
    use HasFactory;

    protected $fillable = [
        'warehouse_id',
        'code',
        'name',
        'status',
        'assigned_to',
        'daily_capacity',
        'metadata',
    ];

    protected $casts = [
        'daily_capacity' => 'integer',
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

    public function packingTasks(): HasMany
    {
        return $this->hasMany(PackingTask::class, 'packing_station_id');
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeForWarehouse($query, int $warehouseId)
    {
        return $query->where('warehouse_id', $warehouseId);
    }
}
