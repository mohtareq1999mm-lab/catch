<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\PromotionActivated;
use App\Notifications\UserPromotionAvailableNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserPromotionAvailableNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(PromotionActivated $event): void
    {
        $promotion = $event->promotion;

        $userModel = config('auth.providers.users.model');

        $userModel::query()
            ->where('type', UserType::USER->value)
            ->chunkById(500, function ($users) use ($promotion) {
                foreach ($users as $user) {
                    if ($user->type !== UserType::USER->value) {
                        continue;
                    }

                    $user->notify(new UserPromotionAvailableNotification($promotion));
                }
            });
    }
}
