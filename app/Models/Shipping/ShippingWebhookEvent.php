<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #143: immutable inbox evidence. No observers, events, or lifecycle hooks.
 */
class ShippingWebhookEvent extends Model
{
    protected $table = 'shipping_webhook_events';

    protected $fillable = [
        'provider_code',
        'mode',
        'account_code',
        'webhook_version',
        'event_key',
        'event_type',
        'provider_shipment_ref',
        'shipment_id',
        'redacted_payload',
        'raw_status',
        'status',
        'last_error',
        'received_at',
        'occurred_at',
    ];

    protected $casts = [
        'redacted_payload' => 'array',
        'received_at' => 'datetime',
        'occurred_at' => 'datetime',
    ];

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
