<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\OrderTrackingEvent;
use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShipmentNotification extends Model
{
    protected $table = 'shipment_notifications';

    protected $fillable = [
        'shipment_id',
        'timeline_event_id',
        'channel',
        'recipient_type',
        'recipient_id',
        'recipient_contact_encrypted',
        'recipient_fingerprint_version',
        'status',
        'source',
        'attempts',
        'max_attempts',
        'next_retry_at',
        'dedupe_key',
        'payload',
        'sent_at',
        'failed_at',
        'failure_reason',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'attempts' => 'integer',
        'max_attempts' => 'integer',
        'next_retry_at' => 'datetime',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    protected $hidden = [
        'recipient_contact_encrypted',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function timelineEvent(): BelongsTo
    {
        return $this->belongsTo(OrderTrackingEvent::class, 'timeline_event_id');
    }
}
