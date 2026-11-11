<?php

namespace Marvel\Database\Models;

use Illuminate\Database\Eloquent\Model;


class   Cart extends Model
{


    protected $table = 'carts';

    public $fillable = [
        'user_id',
        'guest_id',
        'coupon',
        'total_price',
        'status',
        'reserved_at',
        'expires_at',
        'guest_expires_at',
    ];

    protected $casts = [
        'reserved_at' => 'datetime',
        'expires_at' => 'datetime',
        'guest_expires_at' => 'datetime',
    ];


    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Guest owner (nullable). Guest carts are resolved from the verified
     * server-side guest credential — never from a client-supplied cart id.
     */
    public function guest()
    {
        return $this->belongsTo(\App\Models\GuestIdentity::class, 'guest_id');
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
