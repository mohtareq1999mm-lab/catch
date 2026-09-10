<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\ReviewApproved;
use App\Notifications\UserReviewApprovedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendUserReviewApprovedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'meem-medium';

    public function handle(ReviewApproved $event): void
    {
        $user = $event->review->user;
        if (!$user) {
            return;
        }
        if (($user->type ?? null) !== UserType::USER->value) {
            return;
        }
        $user->notify(new UserReviewApprovedNotification($event->review));
    }
}
