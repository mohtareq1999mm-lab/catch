<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\CouponAssignment;

class CouponAssigned
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public CouponAssignment $assignment,
    ) {}
}
