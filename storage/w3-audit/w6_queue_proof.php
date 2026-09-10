<?php

/**
 * W6 QUEUE PROOF — real database-queue round trip.
 *
 * Run with scratch DB + QUEUE_CONNECTION=database:
 *   DB_CONNECTION=sqlite DB_DATABASE=<file> QUEUE_CONNECTION=database \
 *     php w6_queue_proof.php
 *
 * Proves: HTTP revoke → LogActivityJob persisted to `jobs` → REAL worker
 * (`queue:work --once`) consumes it → terminal activity_log row exists.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$fail=[]; $total=0;
function gate(string $n,bool $ok):void{ global $fail,$total; $total++; echo ($ok?'PASS':'FAIL').": $n\n"; if(!$ok)$fail[]=$n; }

if ((string) config('queue.default') !== 'database') { exit('Set QUEUE_CONNECTION=database for this proof.'."\n"); }

Artisan::call('migrate:fresh', ['--force' => true]);
$pdo = DB::getPdo();
if (DB::getDriverName() === 'sqlite') {
    $pdo->sqliteCreateFunction('now', fn () => date('Y-m-d H:i:s'));
}

foreach (['view-orders','manage-digital-access'] as $p) {
    $pdo->prepare('INSERT INTO permissions (name,guard_name,created_at,updated_at) VALUES (?,?,now(),now())')->execute([$p,'api']);
}
$pdo->prepare('INSERT INTO users (name,email,password,type,is_active,created_at,updated_at) VALUES (?,?,?,?,1,now(),now())')
    ->execute(['q-admin',uniqid('q').'@e.com',password_hash('x',PASSWORD_BCRYPT),'admin']);
$adminId=(int)$pdo->lastInsertId();
foreach ($pdo->query('SELECT id FROM permissions') as $r) {
    $pdo->prepare('INSERT INTO model_has_permissions (permission_id,model_type,model_id) VALUES (?,?,?)')->execute([$r['id'],'Marvel\Database\Models\User',$adminId]);
}
$t='q-'.bin2hex(random_bytes(16));
$pdo->prepare('INSERT INTO personal_access_tokens (tokenable_type,tokenable_id,name,token,created_at) VALUES (?,?,?,?,now())')
    ->execute(['Marvel\Database\Models\User',$adminId,'proof',hash('sha256',$t)]);

$pdo->prepare('INSERT INTO products (slug,name,description,price,item_type,status,in_stock,created_at,updated_at) VALUES (?,?,?,?,?,1,1,now(),now())')
    ->execute(['q-'.uniqid(),json_encode(['en'=>'Q']),json_encode(['en'=>'q']),3,'DIGITAL']);
$productId=(int)$pdo->lastInsertId();
$orderCols = collect($pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC));
$oVals = ['user_id'=>$adminId,'status'=>'completed','payment_status'=>'paid','name'=>json_encode(['en'=>'O'])];
$oN=[];$oB=[];
foreach ($orderCols as $c) { $n=$c['name'];
  if (isset($oVals[$n])) { $oN[]=$n; $oB[]=$oVals[$n]; continue; }
  if ((int)$c['notnull']===1 && $c['dflt_value']===null && $n!=='id') { $ty=strtolower((string)$c['type']); $oN[]=$n; $oB[]=str_contains($ty,'int')?0:(str_contains($ty,'text')||$ty==='json'?'{}':'q'); }
}
$pdo->prepare('INSERT INTO orders ('.implode(',',$oN).') VALUES ('.implode(',',array_fill(0,count($oN),'?')).')')->execute($oB);
$orderId=(int)$pdo->lastInsertId();
$iCols = collect($pdo->query("PRAGMA table_info('order_products')")->fetchAll(PDO::FETCH_ASSOC));
$iVals = ['order_id'=>$orderId,'product_id'=>$productId,'product_name'=>json_encode(['en'=>'Q']),'item_type'=>'DIGITAL','product_quantity'=>1];
$iN=[];$iB=[];
foreach ($iCols as $c) { $n=$c['name'];
  if (isset($iVals[$n])) { $iN[]=$n; $iB[]=$iVals[$n]; continue; }
  if ((int)$c['notnull']===1 && $c['dflt_value']===null && $n!=='id') { $ty=strtolower((string)$c['type']); $iN[]=$n; $iB[]=str_contains($ty,'int')?0:(str_contains($ty,'text')||$ty==='json'?'{}':'q'); }
}
$pdo->prepare('INSERT INTO order_products ('.implode(',',$iN).') VALUES ('.implode(',',array_fill(0,count($iN),'?')).')')->execute($iB);
$itemId=(int)$pdo->lastInsertId();
$entUuid=(string)\Ramsey\Uuid\Uuid::uuid4();
$pdo->prepare('INSERT INTO digital_entitlements (uuid,order_id,order_product_id,user_id,status,download_limit,download_count,delivered_at,created_at,updated_at) VALUES (?,?,?,?,?,1,0,now(),now(),now())')
    ->execute([$entUuid,$orderId,$itemId,$adminId,'delivered']);

/* 1. action through the HTTP boundary */
$req = Request::create("/api/v1/digital-entitlements/{$entUuid}/revoke",'POST');
$req->headers->set('Authorization','Bearer '.$t);
$req->headers->set('Accept','application/json');
$app->instance('request',$req);
$res = $kernel->handle($req);
gate('revoke via HTTP → 200', $res->getStatusCode()===200);

/* 2. job persisted to the real queue table */
$jobsCount = (int) $pdo->query("SELECT count(*) FROM jobs WHERE payload LIKE '%LogActivityJob%'")->fetchColumn();
gate('LogActivityJob persisted to jobs table', $jobsCount >= 1);

$activityBefore = (int) $pdo->query("SELECT count(*) FROM activity_log WHERE event='digital.entitlement.revoked'")->fetchColumn();
gate('activity row NOT yet written (still queued)', $activityBefore === 0);

/* 3. REAL worker consumption — job lives on the named meem-medium queue */
Artisan::call('queue:work', ['--once' => true, '--queue' => 'meem-medium', '--stop-when-empty' => true]);
echo "worker-output: " . trim(Artisan::output()) . "\n";
gate('no failed jobs after worker run', (int) $pdo->query("SELECT count(*) FROM failed_jobs")->fetchColumn() === 0);

/* 4. terminal state */
$activityAfter = (int) $pdo->query("SELECT count(*) FROM activity_log WHERE event='digital.entitlement.revoked'")->fetchColumn();
gate('worker consumed job → activity_log row written', $activityBefore === 0 && $activityAfter === 1);

echo "\n==== W6 QUEUE PROOF: ".($total-count($fail))."/{$total} ====\n";
exit(empty($fail)?0:1);
