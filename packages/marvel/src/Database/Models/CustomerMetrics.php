<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMetrics extends Model
{
    protected $table = 'customer_metrics';

    protected $fillable = [
        'user_id',
        'completed_orders',
        'total_qualifying_order_value',
        'first_order_at',
        'last_order_at',
        'coupons_used',
        'computed_at',
    ];

    protected $casts = [
        'completed_orders' => 'integer',
        'total_qualifying_order_value' => 'decimal:2',
        'first_order_at' => 'datetime',
        'last_order_at' => 'datetime',
        'coupons_used' => 'integer',
        'computed_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
