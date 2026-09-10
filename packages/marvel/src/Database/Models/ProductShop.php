<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class ProductShop extends Pivot
{
    use SoftDeletes;

    protected $table = 'product_shop';

    
    public $timestamps = false;
}
