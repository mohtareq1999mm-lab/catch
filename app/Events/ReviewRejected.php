<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Marvel\Database\Models\Review;

class ReviewRejected
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Review $review,
    ) {}
}
