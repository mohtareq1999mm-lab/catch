<?php

/** W5 concurrency worker: one process = one fulfillment attempt. */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$orderId = (int) ($argv[1] ?? 0);
if (!$orderId) {
    exit(2);
}

try {
    $order = \Marvel\Database\Models\Order::query()->findOrFail($orderId);
    app(\App\Services\Digital\DigitalFulfillmentService::class)->fulfillOrder($order);
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[worker ' . getmypid() . '] ' . get_class($e) . ': ' . $e->getMessage() . "\n");
    exit(1);
}
