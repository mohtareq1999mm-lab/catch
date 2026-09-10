<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class AssignedCouponConsumed
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public $coupon,
        public $couponAssignment,
        public $user,
        public $order,
        public int $remainingUses,
        public $consumedAt,
    ) {}
}
