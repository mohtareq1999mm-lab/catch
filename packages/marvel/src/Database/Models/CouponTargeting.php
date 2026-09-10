<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CouponTargeting extends Model
{
    protected $table = 'coupon_targetings';

    protected $fillable = [
        'coupon_id',
        'mode',
        'require_claim',
        'max_claims',
        'rule_tree',
    ];

    protected $casts = [
        'require_claim' => 'boolean',
        'max_claims' => 'integer',
        'rule_tree' => 'array',
    ];

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }
}
