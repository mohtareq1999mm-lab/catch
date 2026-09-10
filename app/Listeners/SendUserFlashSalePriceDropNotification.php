<?php

namespace App\Listeners;

use App\Actions\NotifyWishlistUsersOfProduct;
use App\Events\FlashSaleActivated;
use App\Notifications\UserFlashSalePriceDropNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Marvel\Database\Models\FlashSale;

class SendUserFlashSalePriceDropNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'meem-medium';

    public function handle(FlashSaleActivated $event): void
    {
        $flashSale = $event->flashSale;

        $flashSale->products()->chunkById(500, function ($products) use ($flashSale) {
            foreach ($products as $product) {
                app(NotifyWishlistUsersOfProduct::class)->handle(
                    $product,
                    new UserFlashSalePriceDropNotification($flashSale)
                );
            }
        });
    }
}
