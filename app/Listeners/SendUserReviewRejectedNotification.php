<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\ReviewRejected;
use App\Notifications\UserReviewRejectedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendUserReviewRejectedNotification implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    use InteractsWithQueue;
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
