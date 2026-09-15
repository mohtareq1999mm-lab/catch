<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$keys = ['PUSHER_APP_KEY','PUSHER_APP_CLUSTER','PUSHER_HOST','PUSHER_PORT','PUSHER_SCHEME','MIX_PUSHER_APP_KEY','MIX_PUSHER_APP_CLUSTER','MIX_PUSHER_HOST','MIX_PUSHER_PORT','MIX_PUSHER_SCHEME','BROADCAST_DRIVER','QUEUE_CONNECTION','APP_URL','SESSION_DOMAIN','SANCTUM_STATEFUL_DOMAINS'];
foreach ($keys as $k) {
  $v = env($k);
  $exists = $v !== null && $v !== '' ? 'yes' : 'no';
  $masked = $exists==='yes' ? substr((string)$v,0,2).'***' : 'null';
  // Never print full secret, only existence and cluster/host
  if (in_array($k, ['PUSHER_APP_CLUSTER','MIX_PUSHER_APP_CLUSTER','PUSHER_HOST','MIX_PUSHER_HOST','PUSHER_SCHEME','MIX_PUSHER_SCHEME','PUSHER_PORT','MIX_PUSHER_PORT','BROADCAST_DRIVER','QUEUE_CONNECTION','APP_URL'])) {
    echo "$k=" . var_export($v,true) . PHP_EOL;
  } else {
    echo "$k exists=$exists masked=$masked" . PHP_EOL;
  }
}
echo "config broadcasting.default=" . config('broadcasting.default') . PHP_EOL;
echo "config pusher cluster=" . var_export(config('broadcasting.connections.pusher.options.cluster'), true) . PHP_EOL;
echo "config queue default=" . config('queue.default') . PHP_EOL;
