<?php

namespace App\Models;

use App\Enums\CouponOutboxStatus;
use Illuminate\Database\Eloquent\Model;

class CouponOutbox extends Model
{
    protected $table = 'coupon_outbox';

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'correlation_id',
        'causation_id',
        'payload',
        'status',
        'attempts',
        'available_at',
        'published_at',
        'last_error',
    ];

    protected $casts = [
        'payload' => 'array',
        'status' => CouponOutboxStatus::class,
        'attempts' => 'integer',
        'available_at' => 'datetime',
        'published_at' => 'datetime',
    ];
}
