<?php


namespace Marvel\Events;

use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\PaymentMethod;

class PaymentMethods implements ShouldQueue
{
    public string $queue;

    /**
     * @var PaymentMethod
     */

    public $payment_methods;

    /**
     * Create a new event instance.
     *
     * @param PaymentMethod $payment_methods
     */
    public function __construct(PaymentMethod $payment_methods)
    {
        $this->queue = \App\Enums\QueueName::medium();
        $this->payment_methods = $payment_methods;
    }
}
