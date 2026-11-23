<?php

declare(strict_types=1);

namespace App\Models\Settlement;

use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

class ShipmentCodAdjustment extends Model
{
    protected $table = 'shipment_cod_adjustments';

    protected $fillable = [
        'statement_id',
        'order_id',
        'currency_code',
        'decimal_places',
        'original_collectible_minor',
        'old_collectible_minor',
        'new_collectible_minor',
        'reason',
        'actor_id',
        'approved_by',
        'approved_at',
        'status',
        'idempotency_key',
        'metadata',
    ];

    protected $casts = [
        'decimal_places' => 'integer',
        'original_collectible_minor' => 'integer',
        'old_collectible_minor' => 'integer',
        'new_collectible_minor' => 'integer',
        'approved_at' => 'datetime',
        'metadata' => 'array',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $adjustment): void {
            $originalStatus = (string) $adjustment->getOriginal('status');
            $newStatus = (string) $adjustment->status;
            $allowedApproval = $originalStatus === 'pending_approval'
                && $newStatus === 'approved'
                && $adjustment->isDirty(['status', 'approved_by', 'approved_at'])
                && count($adjustment->getDirty()) === count(array_intersect(array_keys($adjustment->getDirty()), ['status', 'approved_by', 'approved_at']));

            if (!$allowedApproval) {
                throw new DomainException('Shipment COD adjustments are append-only.');
            }
        });

        static::deleting(function (): void {
            throw new DomainException('Shipment COD adjustments are append-only.');
        });
    }

    public function statement(): BelongsTo
    {
        return $this->belongsTo(CodSettlementStatement::class, 'statement_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
