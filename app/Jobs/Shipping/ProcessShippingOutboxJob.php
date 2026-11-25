<?php

declare(strict_types=1);

namespace App\Jobs\Shipping;

use App\Enums\QueueName;
use App\Services\Shipment\Outbox\ShippingOutboxService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessShippingOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public int $timeout = 120;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300, 600];
    }

    public function __construct(public readonly int $outboxId)
    {
        $this->onQueue(QueueName::high());
    }

    public function handle(ShippingOutboxService $outbox): void
    {
        if (! $outbox->processById($this->outboxId)) {
            $this->delete();
        }
    }
}
