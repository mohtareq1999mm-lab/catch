<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;


class   Cart extends Model
{


    protected $table = 'carts';

    public $fillable = [
        'user_id',
        'coupon',
        'total_price',
        'status',
        'reserved_at',
        'expires_at',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function items()
    {
        return $this->hasMany(CartItem::class);
    }

    public function scheduledItems()
    {
        return $this->hasMany(CartItem::class)->where('shipping_method', 'SCHEDULED');
    }

    public function fastItems()
    {
        return $this->hasMany(CartItem::class)->where('shipping_method', 'FAST');
    }
}
