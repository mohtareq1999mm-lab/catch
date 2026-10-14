<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class CouponShop extends Pivot
{
    use SoftDeletes;

    protected $table = 'coupon_shop';

    
    public $timestamps = false;
}
