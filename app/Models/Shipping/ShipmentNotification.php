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
        'notification_recipient_id',
        'status',
        'dedupe_key',
        'payload',
        'sent_at',
        'failed_at',
        'failure_reason',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'failed_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }

    public function timelineEvent(): BelongsTo
    {
        return $this->belongsTo(OrderTrackingEvent::class, 'timeline_event_id');
    }

    public function notificationRecipient(): BelongsTo
    {
        return $this->belongsTo(NotificationRecipient::class, 'notification_recipient_id');
    }
}
