<?php

namespace Marvel\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Events\ProductReviewRejected;
use Marvel\Notifications\ProductRejectedNotification;

class ProductReviewRejectedListener implements ShouldQueue
{
    public function viaQueue($event = null): string
    {
        return \App\Enums\QueueName::high();
    }

    /**
     * Handle the event.
     *
     * @param  ProductReview $event
     * @return void
     */
    public function handle(ProductReviewRejected $event)
    {
        $vendor = $event->product->shop->owner;
        $vendor->notify(new ProductRejectedNotification($event->product));
    }
}
