<?php

declare(strict_types=1);

namespace App\Models\Shipping;

use Illuminate\Database\Eloquent\Model;

class ShippingPollCursor extends Model
{
    protected $table = 'shipping_poll_cursors';

    protected $fillable = [
        'provider_code',
        'mode',
        'account_code',
        'cursor',
        'last_polled_at',
        'last_success_at',
        'last_error',
    ];

    protected $casts = [
        'last_polled_at' => 'datetime',
        'last_success_at' => 'datetime',
    ];
}
