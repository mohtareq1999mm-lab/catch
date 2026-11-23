<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #151: processed provider-event evidence. No observers and no timeline writes.
 */
class ShippingProviderEvent extends Model
{
    protected $table = 'shipping_provider_events';

    protected $fillable = [
        'webhook_event_id',
        'shipment_id',
        'provider_code',
        'mode',
        'account_code',
        'provider_event_key',
        'raw_status',
        'occurred_at',
        'received_at',
        'ordering_key',
        'mapping_version',
        'mapped_status',
        'exception_classification',
        'payload_reference',
        'payload_hash',
        'applied_at',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(ShippingWebhookEvent::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
