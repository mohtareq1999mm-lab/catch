<?php


namespace Marvel\Events;


use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\Order;

class OrderDelivered implements ShouldQueue
{
    public $queue = 'meem-medium';

    /**
     * @var Order
     */

    public Order $order;


    /**
     * Create a new event instance.
     *
     * @param Order $order
     */
    public function __construct(Order $order)
    {
        $this->order = $order;
    }
}
