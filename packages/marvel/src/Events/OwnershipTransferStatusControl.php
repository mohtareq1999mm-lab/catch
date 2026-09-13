<?php


namespace Marvel\Events;


use Illuminate\Contracts\Queue\ShouldQueue;
use Marvel\Database\Models\OwnershipTransfer;


class OwnershipTransferStatusControl implements ShouldQueue
{
    public string $queue;

    /**
     * @var OwnershipTransfer
     */

    public OwnershipTransfer $ownershipTransfer;


    /**
     * Create a new event instance.
     *
     * @param OwnershipTransfer $ownershipTransfer
     */
    public function __construct(OwnershipTransfer $ownershipTransfer)
    {
        $this->queue = \App\Enums\QueueName::medium();
        $this->ownershipTransfer = $ownershipTransfer;
    }
}
