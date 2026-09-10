<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;

/**
 * Temporary coupon reservation during payment window.
 *
 * Prevents double-booking of single-use coupons by reserving them
 * during the payment attempt (30min TTL). Consumed on payment success,
 * released on payment failure or expiration.
 */
class CouponReservation extends Model
{
    protected $fillable = [
        'coupon_id',
        'user_id',
        'order_id',
        'reserved_at',
        'expires_at',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
