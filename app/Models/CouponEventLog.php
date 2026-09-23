<?php

namespace App\Models;

use App\Enums\CouponEventStatus;
use Illuminate\Database\Eloquent\Model;

class CouponEventLog extends Model
{
    protected $table = 'coupon_event_logs';

    protected $fillable = [
        'event_id',
        'event_type',
        'aggregate_type',
        'aggregate_id',
        'user_id',
        'distribution_run_id',
        'correlation_id',
        'causation_id',
        'status',
        'queue',
        'routing_key',
        'consumer',
        'attempt',
        'occurred_at',
        'published_at',
        'consumed_at',
        'completed_at',
        'failed_at',
        'duration_ms',
        'error_code',
        'error_message',
        'metadata',
    ];

    protected $casts = [
        'status' => CouponEventStatus::class,
        'user_id' => 'integer',
        'distribution_run_id' => 'integer',
        'attempt' => 'integer',
        'occurred_at' => 'datetime',
        'published_at' => 'datetime',
        'consumed_at' => 'datetime',
        'completed_at' => 'datetime',
        'failed_at' => 'datetime',
        'duration_ms' => 'integer',
        'metadata' => 'array',
    ];
}
