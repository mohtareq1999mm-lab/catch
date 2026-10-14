<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Database\Eloquent\SoftDeletes;

class CategoryShop extends Pivot
{
    use SoftDeletes;

    protected $table = 'category_shop';

    
    public $timestamps = false;
}
