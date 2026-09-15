<?php
$envPath = __DIR__ . '/../.env';
$env = file_get_contents($envPath);
if (preg_match('/^QUEUE_CONNECTION=sync/m', $env)) {
  $env = preg_replace('/^QUEUE_CONNECTION=sync/m', 'QUEUE_CONNECTION=database', $env);
  file_put_contents($envPath, $env);
  echo "Changed QUEUE_CONNECTION to database\n";
} else {
  echo "Already not sync: " . (preg_match('/^QUEUE_CONNECTION=(.*)$/m', $env, $m) ? $m[1] : 'unknown') . "\n";
}
