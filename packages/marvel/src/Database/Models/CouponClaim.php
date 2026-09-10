<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponClaim extends Model
{
    protected $table = 'coupon_claims';

    protected $fillable = [
        'coupon_id',
        'user_id',
        'claimed_at',
        'eligibility_snapshot',
    ];

    protected $casts = [
        'claimed_at' => 'datetime',
        'eligibility_snapshot' => 'array',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
