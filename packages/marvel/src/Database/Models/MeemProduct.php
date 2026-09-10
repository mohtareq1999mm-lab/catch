<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;

class MeemProduct extends Model
{
    protected $table = 'meem_products';

    public $guarded = [];

    protected $casts = [
        'price' => 'decimal:2',
    ];
}
