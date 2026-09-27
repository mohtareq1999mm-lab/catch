<?php

namespace App\Models\OrderFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrderFlowStatus extends Model
{
    protected $table = 'order_flow_statuses';

    protected $fillable = [
        'flow_id',
        'status_id',
        'sort_order',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(OrderFlow::class, 'flow_id');
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(OrderStatus::class, 'status_id');
    }
}
