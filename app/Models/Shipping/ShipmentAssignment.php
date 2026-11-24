<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ShipmentAssignment extends Model
{
    public const STATUS_ACTIVE = 'active';

    protected $table = 'shipment_assignments';

    protected $fillable = [
        'shipment_id',
        'delivery_representative_id',
        'status',
        'assigned_at',
        'unassigned_at',
        'assigned_by',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'assigned_at' => 'datetime',
        'unassigned_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function deliveryRepresentative(): BelongsTo
    {
        return $this->belongsTo(DeliveryRepresentative::class);
    }

    public function deliveryAttempts(): HasMany
    {
        return $this->hasMany(ShipmentDeliveryAttempt::class);
    }

    public function pods(): HasMany
    {
        return $this->hasMany(ShipmentPod::class);
    }
}
