<?php

namespace App\Models;

use App\Enums\CouponDistributionRecipientStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponDistributionRecipient extends Model
{
    protected $table = 'coupon_distribution_recipients';

    protected $fillable = [
        'run_id',
        'coupon_id',
        'user_id',
        'tree_hash',
        'status',
        'notified_at',
        'error',
        'attempts',
    ];

    protected $casts = [
        'status' => CouponDistributionRecipientStatus::class,
        'notified_at' => 'datetime',
        'attempts' => 'integer',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(CouponDistributionRun::class, 'run_id');
    }
}
