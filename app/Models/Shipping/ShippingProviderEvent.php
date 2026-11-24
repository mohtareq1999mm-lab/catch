<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #151: processed provider-event evidence with no timeline-write authority.
 *
 * #175: identity and payload snapshots are restored on Eloquent updates and
 * enforced by a MySQL BEFORE UPDATE trigger (see the #175 migration). The
 * model guard is defense-in-depth on non-MySQL connections; DELETE/TRUNCATE
 * and DDL are outside the trigger's scope.
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

    /** @var list<string> */
    private const SNAPSHOT_FIELDS = [
        'provider_code',
        'mode',
        'account_code',
        'provider_event_key',
        'raw_status',
        'occurred_at',
        'received_at',
        'ordering_key',
        'mapping_version',
        'payload_reference',
        'payload_hash',
    ];

    protected $casts = [
        'occurred_at' => 'datetime',
        'received_at' => 'datetime',
        'applied_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(static function (self $event): void {
            foreach (self::SNAPSHOT_FIELDS as $field) {
                if ($event->isDirty($field)) {
                    $event->{$field} = $event->getOriginal($field);
                }
            }
        });
    }

    public function webhookEvent(): BelongsTo
    {
        return $this->belongsTo(ShippingWebhookEvent::class);
    }

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
