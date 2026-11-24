<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Models\Shipment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * #143: immutable inbox evidence with no shipment lifecycle-write authority.
 *
 * #175: identity and payload snapshots are restored on Eloquent updates and
 * enforced by a MySQL BEFORE UPDATE trigger (see the #175 migration). The
 * model guard is defense-in-depth on non-MySQL connections; DELETE/TRUNCATE
 * and DDL are outside the trigger's scope.
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

    /** @var list<string> */
    private const SNAPSHOT_FIELDS = [
        'provider_code',
        'mode',
        'account_code',
        'webhook_version',
        'event_key',
        'event_type',
        'provider_shipment_ref',
        'redacted_payload',
        'raw_status',
        'occurred_at',
        'received_at',
    ];

    protected $casts = [
        'redacted_payload' => 'array',
        'received_at' => 'datetime',
        'occurred_at' => 'datetime',
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

    public function shipment(): BelongsTo
    {
        return $this->belongsTo(Shipment::class);
    }
}
