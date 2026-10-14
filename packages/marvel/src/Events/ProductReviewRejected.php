<?php


namespace Marvel\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\Product;

class ProductReviewRejected implements ShouldQueue
{
    public string $queue;

    /**
     * @var Product
     */

    public $product;

    /**
     * Create a new event instance.
     *
     * @param Product $product
     */
    public function __construct(Product $product)
    {
        $this->queue = \App\Enums\QueueName::medium();
        $this->product = $product;
    }
}
