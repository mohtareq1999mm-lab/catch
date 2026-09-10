<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Domain Responsibility: CouponAssignment owns the per-user quota
 * (max_uses, used), assignment timestamp, optional expiration, and
 * usage history. Each row represents a single user's grant to use
 * a specific coupon up to max_uses times.
 *
 * A coupon with zero assignments is public (anyone can use it once).
 * A coupon with one or more assignments is restricted (only assigned
 * users can use it, up to their individual quota).
 */
class CouponAssignment extends Model
{
    protected $table = 'coupon_assignments';

    protected $fillable = [
        'coupon_id',
        'user_id',
        'max_uses',
        'used',
        'assigned_at',
        'expires_at',
    ];

    protected $casts = [
        'max_uses' => 'integer',
        'used' => 'integer',
        'assigned_at' => 'datetime',
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

    public function usages(): HasMany
    {
        return $this->hasMany(CouponAssignmentUsage::class, 'coupon_assignment_id');
    }
}
