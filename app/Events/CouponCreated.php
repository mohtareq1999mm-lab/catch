<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Coupon;

class CouponCreated
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Coupon $coupon,
    ) {}
}
