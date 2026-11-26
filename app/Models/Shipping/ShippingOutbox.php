<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use App\Enums\Shipping\ShippingOutboxStatus;
use Illuminate\Database\Eloquent\Model;

class ShippingOutbox extends Model
{
    protected $table = 'shipping_outbox';

    protected $fillable = [
        'operation',
        'aggregate_type',
        'aggregate_id',
        'idempotency_key',
        'provider_code',
        'provider_mode',
        'payload',
        'result',
        'status',
        'attempts',
        'available_at',
        'processing_started_at',
        'dispatch_started_at',
        'processed_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'result' => 'array',
        'status' => ShippingOutboxStatus::class,
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'processing_started_at' => 'datetime',
        'dispatch_started_at' => 'datetime',
        'processed_at' => 'datetime',
    ];
}
