<?php

namespace App\Listeners;

use App\Actions\NotifyWishlistUsersOfProduct;
use App\Events\PromotionActivated;
use App\Notifications\UserPromotionPriceDropNotification;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Marvel\Database\Models\Promotion;

class SendUserPromotionPriceDropNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $queue = 'meem-medium';

    public function handle(PromotionActivated $event): void
    {
        $promotion = $event->promotion;

        $promotion->products()->chunkById(500, function ($products) use ($promotion) {
            foreach ($products as $product) {
                app(NotifyWishlistUsersOfProduct::class)->handle(
                    $product,
                    new UserPromotionPriceDropNotification($promotion)
                );
            }
        });
    }
}
