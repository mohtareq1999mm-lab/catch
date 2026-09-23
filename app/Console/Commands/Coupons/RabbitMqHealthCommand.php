<?php

namespace App\Console\Commands\Coupons;

use App\Services\Coupon\Distribution\Messaging\RabbitMqHealthService;
use Illuminate\Console\Command;

class RabbitMqHealthCommand extends Command
{
    protected $signature = 'coupon:rabbitmq-health';

    protected $description = 'Probe RabbitMQ reachability and coupon topology availability.';

    public function handle(RabbitMqHealthService $health): int
    {
        $result = $health->check();

        if ($result['reachable']) {
            $this->info('RabbitMQ reachable; exchange ['.$result['exchange'].'] available.');
            $this->table(['Queue', 'Name'], collect($result['queues'])->map(
                static fn ($name, $consumer) => [$consumer, $name]
            )->values()->all());

            return self::SUCCESS;
        }

        $this->error('RabbitMQ NOT reachable: '.($result['error'] ?? 'unknown'));

        return self::FAILURE;
    }
}
