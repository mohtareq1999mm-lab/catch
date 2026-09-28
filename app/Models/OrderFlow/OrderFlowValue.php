<?php

namespace App\Models\OrderFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Order;

/**
 * Immutable audit snapshot of a validated Flow Input value.
 *
 * Never the system of record: business copies live in their owning
 * domains (orders / fulfillments). One row per (order, key, context).
 */
class OrderFlowValue extends Model
{
    protected $table = 'order_flow_values';

    protected $fillable = [
        'order_id',
        'flow_id',
        'input_key',
        'value',
        'context',
        'validated_at',
    ];

    protected $casts = [
        'value' => 'array',
        'validated_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }

    public function flow(): BelongsTo
    {
        return $this->belongsTo(OrderFlow::class, 'flow_id');
    }
}
