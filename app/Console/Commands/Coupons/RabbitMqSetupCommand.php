<?php

namespace App\Console\Commands\Coupons;

use App\Services\Coupon\Distribution\Messaging\CouponEventTransport;
use Illuminate\Console\Command;

class RabbitMqSetupCommand extends Command
{
    protected $signature = 'coupon:rabbitmq-setup';

    protected $description = 'Declare the coupon RabbitMQ topology (exchanges, queues, retry queues, DLQs, bindings). Idempotent.';

    public function handle(CouponEventTransport $transport): int
    {
        $transport->declareTopology();

        $this->info('Coupon RabbitMQ topology declared.');

        return self::SUCCESS;
    }
}
