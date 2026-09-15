<?php
$envPath = __DIR__ . '/../.env';
$env = file_get_contents($envPath);
$original = $env;

// Ensure MIX vars mirror PUSHER vars if missing
$pusherKey = null;
$pusherCluster = null;
if (preg_match('/^PUSHER_APP_KEY=(.*)$/m', $env, $m)) { $pusherKey = trim($m[1]); }
if (preg_match('/^PUSHER_APP_CLUSTER=(.*)$/m', $env, $m)) { $pusherCluster = trim($m[1]); }

$added = [];
if (!preg_match('/^MIX_PUSHER_APP_KEY=/m', $env)) {
  $env .= PHP_EOL . "MIX_PUSHER_APP_KEY=" . $pusherKey . PHP_EOL;
  $added[] = "MIX_PUSHER_APP_KEY";
}
if (!preg_match('/^MIX_PUSHER_APP_CLUSTER=/m', $env)) {
  $env .= "MIX_PUSHER_APP_CLUSTER=" . $pusherCluster . PHP_EOL;
  $added[] = "MIX_PUSHER_APP_CLUSTER";
}
// Also add HOST/PORT/SCHEME as empty if missing (frontend will default)
if (!preg_match('/^MIX_PUSHER_HOST=/m', $env)) { $env .= "MIX_PUSHER_HOST=" . PHP_EOL; $added[]="MIX_PUSHER_HOST"; }
if (!preg_match('/^MIX_PUSHER_PORT=/m', $env)) { $env .= "MIX_PUSHER_PORT=" . PHP_EOL; $added[]="MIX_PUSHER_PORT"; }
if (!preg_match('/^MIX_PUSHER_SCHEME=/m', $env)) { $env .= "MIX_PUSHER_SCHEME=https" . PHP_EOL; $added[]="MIX_PUSHER_SCHEME"; }

// Ensure BROADCAST_DRIVER remains pusher and QUEUE_CONNECTION for real queue test
// For this certification, we need real queue. Keep original QUEUE_CONNECTION but note.
// We will temporarily use database queue via config override in test harness; do not change .env queue permanently to avoid breaking dev.
// However, add comment for verification.

if ($added) {
  file_put_contents($envPath, $env);
  echo "Added to .env: " . implode(", ", $added) . PHP_EOL;
} else {
  echo "No .env changes needed" . PHP_EOL;
}

// Also update .env.example if missing MIX vars
$examplePath = __DIR__ . '/../.env.example';
$example = file_get_contents($examplePath);
$exAdded = [];
if (!preg_match('/^MIX_PUSHER_APP_KEY=/m', $example)) {
  $example .= PHP_EOL . "MIX_PUSHER_APP_KEY=\${PUSHER_APP_KEY}" . PHP_EOL;
  $exAdded[]="MIX_PUSHER_APP_KEY";
}
if (!preg_match('/^MIX_PUSHER_APP_CLUSTER=/m', $example)) {
  $example .= "MIX_PUSHER_APP_CLUSTER=\${PUSHER_APP_CLUSTER}" . PHP_EOL;
  $exAdded[]="MIX_PUSHER_APP_CLUSTER";
}
if ($exAdded) {
  file_put_contents($examplePath, $example);
  echo "Added to .env.example: " . implode(", ", $exAdded) . PHP_EOL;
}
echo "Done" . PHP_EOL;
