<?php

namespace App\Listeners;

use App\Actions\NotifyWishlistUsersOfProduct;
use App\Events\ProductPriceDrop;
use App\Notifications\UserProductPriceDropNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendUserProductPriceDropNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'meem-medium';

    public function handle(ProductPriceDrop $event): void
    {
        app(NotifyWishlistUsersOfProduct::class)->handle(
            $event->product,
            new UserProductPriceDropNotification($event->product, $event->oldPrice, $event->newPrice)
        );
    }
}
