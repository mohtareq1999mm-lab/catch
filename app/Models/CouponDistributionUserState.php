<?php

namespace App\Models;

use App\Enums\CouponDistributionUserState as UserState;
use Illuminate\Database\Eloquent\Model;

class CouponDistributionUserState extends Model
{
    protected $table = 'coupon_distribution_user_states';

    protected $fillable = [
        'coupon_id',
        'user_id',
        'tree_hash',
        'state',
        'last_run_id',
        'last_recipient_id',
        'last_evaluated_at',
        'notified_at',
    ];

    protected $casts = [
        'state' => UserState::class,
        'last_evaluated_at' => 'datetime',
        'notified_at' => 'datetime',
    ];
}
