<?php

namespace App\Listeners;

use App\Enums\UserType;
use App\Events\FlashSaleActivated;
use App\Notifications\UserFlashSaleAvailableNotification;
use Illuminate\Contracts\Queue\ShouldQueue;

class SendUserFlashSaleAvailableNotification implements ShouldQueue
{
    public $queue = 'meem-medium';

    public function handle(FlashSaleActivated $event): void
    {
        $flashSale = $event->flashSale;

        $userModel = config('auth.providers.users.model');

        $userModel::query()
            ->where('type', UserType::USER->value)
            ->chunkById(500, function ($users) use ($flashSale) {
                foreach ($users as $user) {
                    if ($user->type !== UserType::USER->value) {
                        continue;
                    }

                    $user->notify(new UserFlashSaleAvailableNotification($flashSale));
                }
            });
    }
}
