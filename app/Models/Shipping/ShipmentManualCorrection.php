<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentManualCorrection extends Model
{
    protected $table = 'shipment_manual_corrections';

    protected $fillable = [
        'shipment_id',
        'order_id',
        'actor_id',
        'timeline_event_id',
        'old_status',
        'new_status',
        'reason',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function timelineEvent(): BelongsTo
    {
        return $this->belongsTo(OrderTrackingEvent::class, 'timeline_event_id');
    }
}
