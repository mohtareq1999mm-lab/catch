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
        'status',
        'claimed_at',
        'expires_at',
        'redeemed_at',
        'eligibility_snapshot',
    ];

    protected $casts = [
        'status' => \App\Enums\CouponClaimStatus::class,
        'claimed_at' => 'datetime',
        'expires_at' => 'datetime',
        'redeemed_at' => 'datetime',
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
