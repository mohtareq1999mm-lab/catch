<?php

/**
 * W6 CONCURRENCY PROOF — real MySQL, cross-process download race.
 *
 * S1: limit=1, TWO independent PHP processes hit the SAME signed URL —
 *     exactly one 200 / one 403; counter == 1 (no double consumption).
 * S2: unlimited sentinel (limit=0) → four consecutive deliveries succeed,
 *     counter tracks every delivery atomically.
 *
 * Run: DB_CONNECTION=mysql DB_DATABASE=<scratch> php w6_concurrency_check.php
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$fail = []; $total = 0;
function check(string $n, bool $ok, string $d = ''): void { global $fail,$total; $total++; echo ($ok?'PASS':'FAIL').": $n".($ok||$d===''?'':" | $d")."\n"; if(!$ok)$fail[]=$n; }

if (DB::getDriverName() !== 'mysql') { exit("MUST run on MySQL.\n"); }
Artisan::call('migrate:fresh', ['--force' => true]);
$pdo = DB::getPdo();

/* ---- fixtures ---- */
foreach (['view-products','create-product','update-product','manage-digital-access','view-orders'] as $p) {
    $pdo->prepare('INSERT INTO permissions (name,guard_name,created_at,updated_at) VALUES (?,?,now(),now())')->execute([$p,'api']);
}
function user($pdo,string $tag,string $type='customer'){ $pdo->prepare('INSERT INTO users (name,email,password,type,is_active,created_at,updated_at) VALUES (?,?,?,?,1,now(),now())')->execute([$tag,uniqid($tag).'@e.com',password_hash('x',PASSWORD_BCRYPT),$type]); return (int)$pdo->lastInsertId(); }
function tokenRow($pdo,int $uid){ $t='w6-'.bin2hex(random_bytes(16)); $pdo->prepare('INSERT INTO personal_access_tokens (tokenable_type,tokenable_id,name,token,created_at) VALUES (?,?,?,?,now())')->execute(['Marvel\Database\Models\User',$uid,'w6',hash('sha256',$t)]); return $t; }

$adminId = user($pdo,'w6-admin','admin');
foreach ($pdo->query('SELECT id FROM permissions') as $r) {
    $pdo->prepare('INSERT INTO model_has_permissions (permission_id,model_type,model_id) VALUES (?,?,?)')->execute([$r['id'],'Marvel\Database\Models\User',$adminId]);
}
$adminTok = tokenRow($pdo,$adminId);
$customerId = user($pdo,'w6-cust');

$pdo->prepare('INSERT INTO products (slug,name,description,price,item_type,status,in_stock,created_at,updated_at) VALUES (?,?,?,?,?,1,1,now(),now())')
    ->execute(['w6-race',json_encode(['en'=>'Race']),json_encode(['en'=>'r']),5,'DIGITAL']);
$productId = (int)$pdo->lastInsertId();

/* FILE asset through REAL HTTP (multipart file via Request::$files) */
$tmpPdf = tempnam(sys_get_temp_dir(), 'w6pdf');
file_put_contents($tmpPdf, "%PDF-1.4\nW6RACE\n%%EOF");
$upload = new \Illuminate\Http\UploadedFile($tmpPdf, 'race.pdf', 'application/pdf', null, true);
$req = Request::create("/api/v1/products/{$productId}/digital-assets",'POST',[],[],['file'=>$upload],[
    'HTTP_ACCEPT'=>'application/json',
    'HTTP_AUTHORIZATION'=>'Bearer '.$adminTok,
]);
$app->instance('request',$req);
$res = $kernel->handle($req);
$assetUuid = json_decode($res->getContent(),true)['data']['uuid'] ?? '';
check('fixture: FILE asset created via multipart HTTP', $res->getStatusCode()===201 && $assetUuid!=='',
    substr($res->getContent(),0,200));

$orderIns=$pdo->prepare('INSERT INTO orders (user_id,status,payment_status,name,created_at,updated_at) VALUES (?,?,?,?,now(),now())');
$orderIns->execute([$customerId,'completed','paid',json_encode(['en'=>'O'])]);
$orderId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO order_products (order_id,product_id,product_name,product_quantity,item_type,created_at,updated_at) VALUES (?,?,?,?,?,now(),now())')
    ->execute([$orderId,$productId,json_encode(['en'=>'Race']),1,'DIGITAL']);
$itemId=(int)$pdo->lastInsertId();
$entUuid=(string)\Ramsey\Uuid\Uuid::uuid4();
$pdo->prepare('INSERT INTO digital_entitlements (uuid,order_id,order_product_id,user_id,status,download_limit,download_count,delivered_at,created_at,updated_at) VALUES (?,?,?,?,?,1,0,now(),now(),now())')
    ->execute([$entUuid,$orderId,$itemId,$customerId,'delivered']);
$entId=(int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO digital_asset_entitlement (digital_entitlement_id,digital_asset_id,granted_at) SELECT ?,id,now() FROM digital_assets WHERE uuid=?')
    ->execute([$entId,$assetUuid]);

/* ---- Scenario 1: two worker PROCESSES race one signed URL (cap=1) ---- */
$signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('general.digital.download',
    now()->addMinutes(10), ['entitlement'=>$entUuid,'asset'=>$assetUuid]);

file_put_contents(__DIR__.'/w6_ctx.json', json_encode([
    'url'=>$signedUrl,
    'db_connection'=>'mysql',
    'db_database'=>config('database.connections.mysql.database'),
]));
@unlink(__DIR__.'/w6_worker_results.json');

$procs=[];
for ($i=0;$i<2;$i++) {
    $pipesN=[];
    $r = proc_open('php '.escapeshellarg(__DIR__.'/w6_download_worker.php').' worker',
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipesN);
    $procs[] = ['r'=>$r,'p'=>$pipesN];
}
foreach ($procs as $proc) {
    fclose($proc['p'][0]);
    $err = stream_get_contents($proc['p'][2]);
    if (trim((string)$err) !== '') { echo "worker-stderr: ".trim((string)$err)."\n"; }
    proc_close($proc['r']);
}
$results = json_decode(@file_get_contents(__DIR__.'/w6_worker_results.json') ?: '[]', true);
sort($results);
check('S1 exactly one 200 and one 403 across racers', $results === [200,403], 'got '.json_encode($results));
$count=(int)$pdo->query("SELECT download_count FROM digital_entitlements WHERE id=$entId")->fetchColumn();
check('S1 counter incremented exactly once', $count===1);

/* ---- Scenario 2: unlimited sentinel ---- */
$pdo->exec("UPDATE digital_entitlements SET download_limit=0, download_count=0 WHERE id=$entId");
$signedUrl = \Illuminate\Support\Facades\URL::temporarySignedRoute('general.digital.download',
    now()->addMinutes(10), ['entitlement'=>$entUuid,'asset'=>$assetUuid]);
file_put_contents(__DIR__.'/w6_ctx.json', json_encode([
    'url'=>$signedUrl,
    'db_connection'=>'mysql',
    'db_database'=>config('database.connections.mysql.database'),
]));
@unlink(__DIR__.'/w6_worker_results.json');
$procs=[];
for ($i=0;$i<4;$i++) {
    $pipesN=[];
    $r = proc_open('php '.escapeshellarg(__DIR__.'/w6_download_worker.php').' worker',
        [0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']], $pipesN);
    $procs[] = ['r'=>$r,'p'=>$pipesN];
}
foreach ($procs as $proc) {
    fclose($proc['p'][0]);
    $err = stream_get_contents($proc['p'][2]);
    if (trim((string)$err) !== '') { echo "worker-stderr: ".trim((string)$err)."\n"; }
    proc_close($proc['r']);
}
$results = json_decode(@file_get_contents(__DIR__.'/w6_worker_results.json') ?: '[]', true);
check('S2 unlimited: four racers ALL delivered', count(array_keys($results,200)) === 4 && count($results)===4, 'got '.json_encode($results));
$count=(int)$pdo->query("SELECT download_count FROM digital_entitlements WHERE id=$entId")->fetchColumn();
check('S2 unlimited counter tracks every delivery atomically', $count===4);

echo "\n==== W6 CONCURRENCY RESULT: ".($total-count($fail))."/{$total} ====\n";
exit(empty($fail)?0:1);
