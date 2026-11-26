<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ShipmentDeliveryAttempt extends Model
{
    protected $table = 'shipment_delivery_attempts';

    protected $fillable = [
        'shipment_id',
        'shipment_assignment_id',
        'attempt_number',
        'status',
        'attempted_at',
        'failure_reason',
        'metadata',
    ];

    protected $casts = [
        'attempt_number' => 'integer',
        'attempted_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ShipmentAssignment::class, 'shipment_assignment_id');
    }

    public function pod(): HasOne
    {
        return $this->hasOne(ShipmentPod::class, 'shipment_delivery_attempt_id');
    }
}
