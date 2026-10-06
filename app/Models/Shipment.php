<?php

namespace App\Models;

use App\Enums\InvoiceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Shipment extends Model
{
    protected $table = 'shipments';

    protected $fillable = [
        'uuid',
        'order_id',
        'fulfillment_id',
        'packing_task_id',
        'tracking_number',
        'idempotency_key',
        'courier',
        'status',
        'shipping_method',
        'shipping_cost',
        'currency',
        'origin_address',
        'destination_address',
        'items',
        'total_weight',
        'weight_unit',
        'shipped_at',
        'estimated_delivery_at',
        'delivered_at',
        'cancelled_by',
        'cancel_source',
        'cancelled_at',
        'cancel_reason',
        'notes',
        'metadata',
    ];

    protected $casts = [
        'origin_address' => 'array',
        'destination_address' => 'array',
        'items' => 'array',
        'metadata' => 'array',
        'shipped_at' => 'datetime',
        'estimated_delivery_at' => 'datetime',
        'delivered_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'shipping_cost' => 'float',
        'total_weight' => 'float',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (self $shipment) {
            if (empty($shipment->uuid)) {
                $shipment->uuid = (string) Str::orderedUuid();
            }
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(\Marvel\Database\Models\Order::class);
    }

    public function fulfillment(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Fulfillment\Fulfillment::class);
    }

    public function packingTask(): BelongsTo
    {
        return $this->belongsTo(\App\Models\Fulfillment\PackingTask::class);
    }

    /**
     * Phase 8 (D8-10): actor that cancelled the shipment. Nullable by
     * design — system/internal cancellations carry no user; never fabricate.
     * Mirrors the Phase-7 fulfillment cancelledBy() convention.
     */
    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(\App\Models\User::class, 'cancelled_by');
    }

    /**
     * Phase 8 (D8-1): terminal states whose rows are retained as history and
     * no longer count as the fulfillment's active shipment. Mirrors the
     * active_fulfillment_id generated-column definition in migration
     * 2026_10_08_000001 — the two lists MUST stay identical.
     */
    public const TERMINAL_STATUSES = ['cancelled', 'delivered', 'returned'];

    public function isActive(): bool
    {
        return !in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::allowedTransitions($this->status), true);
    }

    /**
     * Phase 8 (P8-2 / F8-9): SINGLE authoritative shipment DAG. The
     * ShipmentStatus enum delegates here; validation and the transition
     * authority can never diverge again. Unknown statuses transition
     * nowhere — the old `default => ['cancelled']` silently blessed
     * unknown states into cancellation and is removed.
     */
    public static function allowedTransitions(string $from): array
    {
        return match ($from) {
            'pending' => ['label_created', 'cancelled'],
            'label_created' => ['picked_up', 'cancelled'],
            'picked_up' => ['in_transit', 'cancelled'],
            'in_transit' => ['out_for_delivery', 'delayed'],
            'out_for_delivery' => ['delivered', 'failed_delivery'],
            'delivered' => [],
            'failed_delivery' => ['out_for_delivery', 'returned'],
            'returned' => [],
            'delayed' => ['in_transit', 'out_for_delivery'],
            'cancelled' => [],
            default => [],
        };
    }
}
