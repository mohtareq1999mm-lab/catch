<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlatformCommission extends Model
{
    protected $table = 'platform_commissions';

    protected $fillable = [
        'order_id',
        'shop_id',
        'order_total',
        'commission_rate',
        'commission_amount',
        'shop_earnings',
        'commission_type',
    ];

    protected $casts = [
        'order_total' => 'decimal:2',
        'commission_rate' => 'decimal:2',
        'commission_amount' => 'decimal:2',
        'shop_earnings' => 'decimal:2',
    ];

    /**
     * @return BelongsTo
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return BelongsTo
     */
    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
