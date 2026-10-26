<?php
/** READ-ONLY: locate the ~1.1s per-request cost — boot vs query vs cache. */
$t0 = microtime(true);
require __DIR__ . '/../../vendor/autoload.php';
$tAutoload = microtime(true);
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$tBoot = microtime(true);

$setting = \Marvel\Database\Models\Settings::first();
$tQuery = microtime(true);

\Cache::get('bench-nonexistent-key');
$tCacheMiss = microtime(true);

\Cache::put('bench-nonexistent-key', 1, 10);
\Cache::get('bench-nonexistent-key');
\Cache::forget('bench-nonexistent-key');
$tCacheRound = microtime(true);

echo 'autoload      = ' . number_format(($tAutoload - $t0) * 1000, 1) . 'ms' . PHP_EOL;
echo 'framework boot= ' . number_format(($tBoot - $tAutoload) * 1000, 1) . 'ms' . PHP_EOL;
echo 'query Settings= ' . number_format(($tQuery - $tBoot) * 1000, 1) . 'ms' . PHP_EOL;
echo 'cache miss get= ' . number_format(($tCacheMiss - $tQuery) * 1000, 1) . 'ms' . PHP_EOL;
echo 'cache put/get/forget = ' . number_format(($tCacheRound - $tCacheMiss) * 1000, 1) . 'ms' . PHP_EOL;
