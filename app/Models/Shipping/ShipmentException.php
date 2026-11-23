<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #151: internal operational exception. Resolution is a separate workflow and
 * never rewrites shipment or timeline history.
 */
class ShipmentException extends Model
{
    protected $table = 'shipment_exceptions';

    protected $fillable = [
        'shipment_id',
        'provider_event_id',
        'type',
        'severity',
        'status',
        'notes',
        'owner_id',
        'sla_due_at',
        'opened_at',
        'resolved_at',
        'resolved_by',
    ];

    protected $casts = [
        'sla_due_at' => 'datetime',
        'opened_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function providerEvent(): BelongsTo
    {
        return $this->belongsTo(ShippingProviderEvent::class);
    }
}
