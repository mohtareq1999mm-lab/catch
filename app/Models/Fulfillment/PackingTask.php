<?php

namespace App\Models\Fulfillment;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use App\Models\User;
use App\Models\Shipment;

class PackingTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'fulfillment_id',
        'packing_station_id',
        'assigned_to',
        'status',
        'assigned_at',
        'started_at',
        'packed_at',
        'verified_at',
        'weight',
        'dimensions',
        'package_materials',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'started_at' => 'datetime',
        'packed_at' => 'datetime',
        'verified_at' => 'datetime',
        'weight' => 'decimal:2',
        'dimensions' => 'array',
        'package_materials' => 'array',
        'metadata' => 'array',
    ];

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(Fulfillment::class);
    }

    public function packingStation(): BelongsTo
    {
        return $this->belongsTo(PackingStation::class);
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function shipment(): HasOne
    {
        return $this->hasOne(Shipment::class, 'packing_task_id');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeAssigned($query)
    {
        return $query->where('status', 'assigned');
    }

    public function scopePacking($query)
    {
        return $query->where('status', 'packing');
    }

    public function scopePacked($query)
    {
        return $query->where('status', 'packed');
    }

    public function scopeVerified($query)
    {
        return $query->where('status', 'verified');
    }

    public function scopeForStation($query, int $stationId)
    {
        return $query->where('packing_station_id', $stationId);
    }

    public function isComplete(): bool
    {
        return $this->status === 'verified';
    }
}
