<?php

/** W6 worker: ONE signed download through the real HTTP kernel. */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;

$ctxFile = __DIR__ . '/w6_ctx.json';
$resFile = __DIR__ . '/w6_worker_results.json';

if (($argv[1] ?? '') !== 'worker') { exit(2); }
$ctx = json_decode(file_get_contents($ctxFile), true);

// Point this process at the SAME scratch database as the orchestrator.
config(['database.connections.mysql.database' => $ctx['db_database']]);
DB::purge('mysql');

$req = Request::create($ctx['url'], 'GET');
$req->headers->set('Accept', 'application/json');
$app->instance('request', $req);

try {
    $res = $kernel->handle($req);
    $status = $res->getStatusCode();
} catch (\Throwable $e) {
    $status = 500;
}

// Record result under an exclusive lock (crash-safe across racers).
$fp = fopen($resFile, 'c+');
flock($fp, LOCK_EX);
$content = stream_get_contents($fp) ?: '[]';
$results = json_decode($content, true) ?: [];
$results[] = $status;
ftruncate($fp, 0);
rewind($fp);
fwrite($fp, json_encode($results));
flock($fp, LOCK_UN);
fclose($fp);

exit(0);
