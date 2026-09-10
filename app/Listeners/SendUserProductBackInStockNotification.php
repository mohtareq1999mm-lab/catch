<?php

namespace App\Listeners;

use App\Actions\NotifyWishlistUsersOfProduct;
use App\Events\ProductBackInStock;
use App\Notifications\UserProductBackInStockNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendUserProductBackInStockNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'meem-medium';

    public function handle(ProductBackInStock $event): void
    {
        app(NotifyWishlistUsersOfProduct::class)->handle(
            $event->product,
            new UserProductBackInStockNotification($event->product)
        );
    }
}
