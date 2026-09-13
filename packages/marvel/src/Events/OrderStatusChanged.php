<?php


namespace Marvel\Events;


use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\Order;

class OrderStatusChanged implements ShouldQueue
{
    public string $queue;

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
        $this->queue = \App\Enums\QueueName::high();
        $this->order = $order;
    }
}
