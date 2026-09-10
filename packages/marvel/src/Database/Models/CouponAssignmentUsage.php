<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Domain Responsibility: CouponAssignmentUsage owns the immutable
 * consumption history for assigned coupon usage. Each row records
 * one instance of a user consuming one unit of their assigned quota.
 * This is an append-only audit log — rows are never updated or deleted.
 */
class CouponAssignmentUsage extends Model
{
    protected $table = 'coupon_assignment_usages';

    protected $fillable = [
        'coupon_assignment_id',
        'order_id',
        'used_at',
    ];

    protected $casts = [
        'used_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function (CouponAssignmentUsage $usage) {
            if (empty($usage->order_id)) {
                throw new \InvalidArgumentException('order_id is required for coupon assignment usage');
            }
        });

        static::updating(function (CouponAssignmentUsage $usage) {
            if (empty($usage->order_id)) {
                throw new \InvalidArgumentException('order_id is required for coupon assignment usage');
            }
        });
    }

    public function couponAssignment(): BelongsTo
    {
        return $this->belongsTo(CouponAssignment::class, 'coupon_assignment_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(CouponAssignment::class, 'coupon_assignment_id');
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'order_id');
    }
}
