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
        'tracking_number',
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

    public function canTransitionTo(string $target): bool
    {
        return in_array($target, self::allowedTransitions($this->status), true);
    }

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
            default => ['cancelled'],
        };
    }
}
