<?php

namespace App\Listeners;

use App\Actions\NotifyWishlistUsersOfProduct;
use App\Events\ProductDiscountChanged;
use App\Notifications\UserProductDiscountChangedNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class SendUserProductDiscountChangedNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'meem-medium';

    public function handle(ProductDiscountChanged $event): void
    {
        app(NotifyWishlistUsersOfProduct::class)->handle(
            $event->product,
            new UserProductDiscountChangedNotification($event->product, $event->oldValues, $event->newValues)
        );
    }
}
