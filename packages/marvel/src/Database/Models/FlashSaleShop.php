<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class FlashSaleShop extends Pivot
{
    use SoftDeletes;

    protected $table = 'flash_sale_shop';


    public $timestamps = false;
}
