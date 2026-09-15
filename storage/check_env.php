<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "broadcasting.default=" . config('broadcasting.default') . PHP_EOL;
echo "broadcasting.pusher.key_exists=" . (config('broadcasting.connections.pusher.key') ? 'yes' : 'no') . PHP_EOL;
echo "broadcasting.pusher.cluster=" . (config('broadcasting.connections.pusher.options.cluster') ?? 'null') . PHP_EOL;
echo "broadcasting.pusher.app_id_exists=" . (config('broadcasting.connections.pusher.app_id') ? 'yes' : 'no') . PHP_EOL;
echo "app.env=" . config('app.env') . PHP_EOL;
echo "queue.default=" . config('queue.default') . PHP_EOL;
echo "queue.medium=" . config('queue.queues.medium') . PHP_EOL;
echo "shop.pusher.enabled=" . var_export(config('shop.pusher.enabled'), true) . PHP_EOL;
echo "MIX_PUSHER_APP_KEY_exists=" . (getenv('MIX_PUSHER_APP_KEY') ? 'yes' : (env('MIX_PUSHER_APP_KEY') ? 'yes' : 'no')) . PHP_EOL;
echo "PUSHER_APP_KEY_exists=" . (env('PUSHER_APP_KEY') ? 'yes' : 'no') . PHP_EOL;
