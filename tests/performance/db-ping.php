<?php
/** READ-ONLY: MySQL responsiveness ping for the load-stage stall diagnosis. */
require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;

$t0 = microtime(true);
$one = DB::selectOne('SELECT 1');
$t1 = microtime(true);
$count = DB::table('products')->where('status', 1)->count();
$t2 = microtime(true);
echo 'SELECT 1 = ' . number_format(($t1 - $t0) * 1000, 1) . "ms" . PHP_EOL;
echo 'products count(' . $count . ') = ' . number_format(($t2 - $t1) * 1000, 1) . "ms" . PHP_EOL;
