<?php

namespace App\Models\OrderFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class OrderStatus extends Model
{
    protected $table = 'order_statuses';

    protected $fillable = [
        'code',
        'name',
        'description',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function flows(): BelongsToMany
    {
        return $this->belongsToMany(OrderFlow::class, 'order_flow_statuses', 'status_id', 'flow_id')
            ->withPivot('sort_order')
            ->withTimestamps();
    }

    public function isReferenced(): bool
    {
        if (OrderFlowStatus::query()->where('status_id', $this->getKey())->exists()) {
            return true;
        }

        try {
            return \Illuminate\Support\Facades\Schema::hasColumn('orders', 'current_status_id')
                && \Marvel\Database\Models\Order::query()->where('current_status_id', $this->getKey())->exists();
        } catch (\Throwable) {
            return true;
        }
    }
}
