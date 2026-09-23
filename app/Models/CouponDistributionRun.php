<?php

namespace App\Models;

use App\Enums\CouponDistributionRunStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CouponDistributionRun extends Model
{
    protected $table = 'coupon_distribution_runs';

    protected $fillable = [
        'coupon_id',
        'trigger_type',
        'trigger_id',
        'tree_hash',
        'dedupe_key',
        'status',
        'candidate_count',
        'eligible_count',
        'not_eligible_count',
        'notified_count',
        'failed_count',
        'duplicate_skipped_count',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'status' => CouponDistributionRunStatus::class,
        'candidate_count' => 'integer',
        'eligible_count' => 'integer',
        'not_eligible_count' => 'integer',
        'notified_count' => 'integer',
        'failed_count' => 'integer',
        'duplicate_skipped_count' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    public function recipients(): HasMany
    {
        return $this->hasMany(CouponDistributionRecipient::class, 'run_id');
    }
}
