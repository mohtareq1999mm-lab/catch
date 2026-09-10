<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\ReviewRejected;
use App\Notifications\UserReviewRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendUserReviewRejectedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'meem-medium';

    public function handle(ReviewRejected $event): void
    {
        $user = $event->review->user;
        if (!$user) {
            return;
        }
        if (($user->type ?? null) !== UserType::USER->value) {
            return;
        }
        $user->notify(new UserReviewRejectedNotification($event->review));
    }
}
