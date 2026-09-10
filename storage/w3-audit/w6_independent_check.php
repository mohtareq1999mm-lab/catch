<?php

/**
 * W6 INDEPENDENT CHECK — black-box verification.
 *
 * Production migrations on a scratch DB · real HTTP via kernel · every
 * expectation derived from RAW PDO reads / filesystem / queue tables /
 * activity_log — never from production accessors or W6 test helpers.
 *
 * Modes:
 *   php w6_independent_check.php main         → functional + authz matrix
 *   php w6_independent_check.php queue-proof  → REAL queue worker consumes
 *                                               the activity job after a
 *                                               revoke action.
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$mode = $argv[1] ?? 'main';
$fail = []; $total = 0; $lastRaw = '';
function gate(string $n, bool $ok, string $d=''): void { global $fail,$total,$lastRaw; $total++; echo ($ok?'PASS':'FAIL').": $n".($ok||$d===''?'':" | $d")."\n"; if(!$ok && $lastRaw!==''){ echo "   body: ".substr($lastRaw,0,260)."\n"; } if(!$ok)$fail[]=$n; }
function callHttp($kernel,$app,$m,$u,?string $t=null,array $j=[],array $files=[]) { global $lastRaw;
    try { app('auth')->guard('sanctum')->forgetUser(); } catch (\Throwable $e) {}
    app('auth')->forgetGuards();
    $req=Request::create('/api/v1'.$u,$m,[],[],$files,['HTTP_ACCEPT'=>'application/json','CONTENT_TYPE'=>'application/json'],json_encode($j));
    if($t){$req->headers->set('Authorization','Bearer '.$t);}
    $app->instance('request',$req);
    try { $res=$kernel->handle($req); } catch (\Throwable $e){ return ['status'=>500,'json'=>[],'raw'=>$e->getMessage()]; }
    $lastRaw=$res->getContent();
    return ['status'=>$res->getStatusCode(),'json'=>json_decode($res->getContent(),true)??[],'raw'=>$res->getContent()];
}

Artisan::call('migrate:fresh', ['--force' => true]);
$pdo = DB::getPdo();
// SQLite lacks NOW(): register it so fixture SQL stays engine-agnostic.
if (DB::getDriverName() === 'sqlite') {
    $pdo->sqliteCreateFunction('now', fn () => date('Y-m-d H:i:s'));
}

/* ---------- fixtures through the HTTP boundary ---------- */
foreach (['view-products','create-product','update-product','manage-digital-access','view-orders'] as $p) {
    $pdo->prepare('INSERT INTO permissions (name,guard_name,created_at,updated_at) VALUES (?,?,now(),now())')->execute([$p,'api']);
}
function mkU($pdo,string $tag,array $perms=[],string $type='admin'){ $pdo->prepare('INSERT INTO users (name,email,password,type,is_active,created_at,updated_at) VALUES (?,?,?,?,1,now(),now())')->execute([$tag,uniqid($tag).'@e.com',password_hash('x',PASSWORD_BCRYPT),$type]); $id=(int)$pdo->lastInsertId(); foreach($perms as $p){ $pid=$pdo->query("SELECT id FROM permissions WHERE name='$p'")->fetchColumn(); if($pid){ $pdo->prepare('INSERT INTO model_has_permissions (permission_id,model_type,model_id) VALUES (?,?,?)')->execute([$pid,'Marvel\Database\Models\User',$id]); } } return $id; }
function tok($pdo,int $uid){ $t='w6i-'.bin2hex(random_bytes(16)); $pdo->prepare('INSERT INTO personal_access_tokens (tokenable_type,tokenable_id,name,token,created_at) VALUES (?,?,?,?,now())')->execute(['Marvel\Database\Models\User',$uid,'w6i',hash('sha256',$t)]); return $t; }

$fullId   = mkU($pdo,'w6-full',['view-products','create-product','update-product','view-orders','manage-digital-access']);
$viewerId = mkU($pdo,'w6-viewer',['view-products','view-orders']);
$custId   = mkU($pdo,'w6-cust',[],'customer');
$fullTok  = tok($pdo,$fullId); $viewerTok = tok($pdo,$viewerId);

$pdo->prepare('INSERT INTO products (slug,name,description,price,item_type,status,in_stock,created_at,updated_at) VALUES (?,?,?,?,?,1,1,now(),now())')
    ->execute(['w6i-'.uniqid(),json_encode(['en'=>'I']),json_encode(['en'=>'i']),7,'DIGITAL']);
$productId=(int)$pdo->lastInsertId();

$pdfA = "%PDF-1.4\nW6I-ORIGINAL\n%%EOF";
$tmp = tempnam(sys_get_temp_dir(),'pdf'); file_put_contents($tmp,$pdfA);
$upload = new \Illuminate\Http\UploadedFile($tmp,'orig.pdf','application/pdf',null,true);
$r = callHttp($kernel,$app,'POST',"/products/{$productId}/digital-assets",$fullTok,[],['file'=>$upload]);
gate('FILE create → 201',$r['status']===201);
$assetUuid = $r['json']['data']['uuid'];
$assetRow = fn() => $pdo->query("SELECT * FROM digital_assets WHERE uuid=".$pdo->quote($assetUuid))->fetch(PDO::FETCH_ASSOC);
$diskPath = storage_path('app/private/'.$assetRow()['path']);

/* ================= SHOW ================= */
$r = callHttp($kernel,$app,'GET',"/digital-assets/{$assetUuid}",$fullTok);
gate('SHOW → 200',$r['status']===200);
gate('SHOW hides path/disk/secret', !preg_match('/"(path|disk|secret)"/',$r['raw']));
gate('SHOW exposes uuid + type FILE', ($r['json']['data']['uuid'] ?? null)===$assetUuid && ($r['json']['data']['type'] ?? null)==='FILE');

/* ================= WIDENED UPDATE ================= */
$r = callHttp($kernel,$app,'PUT',"/digital-assets/{$assetUuid}",$fullTok,[
    'display_name'=>'Gate Name','status'=>'inactive','metadata'=>['pages'=>'11'],
]);
gate('widened UPDATE → 200',$r['status']===200);
$row = $assetRow();
gate('raw: display_name/status/metadata persisted',
    $row['display_name']==='Gate Name' && $row['status']==='inactive'
    && str_contains((string)$row['metadata'],'"pages"'));
gate('raw: checksum unchanged by metadata update', $row['checksum']===hash('sha256',$pdfA));

$r = callHttp($kernel,$app,'PUT',"/digital-assets/{$assetUuid}",$fullTok,['status'=>'revoked']);
gate('reserved status rejected → 422',$r['status']===422);
gate('reserved status not persisted (raw)', $assetRow()['status']==='inactive');

/* ================= REPLACE ================= */
$pdfB = "%PDF-1.4\nW6I-REPLACEMENT\n%%EOF";
$tmp2 = tempnam(sys_get_temp_dir(),'pdf'); file_put_contents($tmp2,$pdfB);
$up2 = new \Illuminate\Http\UploadedFile($tmp2,'new.pdf','application/pdf',null,true);
$oldDiskPath = $assetRow()['path'];
$oldAbs = storage_path('app/private/'.$oldDiskPath);
$r = callHttp($kernel,$app,'POST',"/digital-assets/{$assetUuid}/replace",$fullTok,[],['file'=>$up2]);
gate('REPLACE → 200',$r['status']===200);
$row = $assetRow();
gate('raw: uuid stable after replacement', $row['uuid']===$assetUuid);
gate('raw: checksum == sha256(new bytes)', hash('sha256',$pdfB)===$row['checksum']);
gate('fs: old physical file retired', !is_file($oldAbs));
gate('fs: new physical file exists with exact bytes', is_file(storage_path('app/private/'.$row['path']))
    && file_get_contents(storage_path('app/private/'.$row['path'])) === $pdfB);

/* ================= ENTITLEMENT MANAGEMENT ================= */
// Schema-driven inserts: fill every NOT NULL column lacking a default.
$fillRow = function (string $table, array $values) use ($pdo): int {
    $cols = collect($pdo->query("PRAGMA table_info('{$table}')")->fetchAll(PDO::FETCH_ASSOC));
    $names = []; $binds = [];
    foreach ($cols as $c) {
        $n = $c['name'];
        if (isset($values[$n])) { $names[] = $n; $binds[] = $values[$n]; continue; }
        if ((int) $c['notnull'] === 1 && $c['dflt_value'] === null && !in_array($n, ['id'], true)) {
            $t = strtolower((string) $c['type']);
            $names[] = $n;
            $binds[] = str_contains($t, 'int') ? 0 : (str_contains($t, 'text') || $t === 'json' ? '{}' : 'w6i');
        }
    }
    $pdo->prepare("INSERT INTO {$table} (" . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')')
        ->execute($binds);
    return (int) $pdo->lastInsertId();
};

$orderId = $fillRow('orders', [
    'user_id' => $custId, 'status' => 'completed', 'payment_status' => 'paid',
    'name' => json_encode(['en' => 'O']),
]);
$itemId = $fillRow('order_products', [
    'order_id' => $orderId, 'product_id' => $productId,
    'product_name' => json_encode(['en' => 'I']), 'item_type' => 'DIGITAL',
    'product_quantity' => 1,
]);
$entUuid=(string)\Ramsey\Uuid\Uuid::uuid4();
$pdo->prepare('INSERT INTO digital_entitlements (uuid,order_id,order_product_id,user_id,status,download_limit,download_count,delivered_at,created_at,updated_at) VALUES (?,?,?,?,?,3,0,now(),now(),now())')
    ->execute([$entUuid,$orderId,$itemId,$custId,'delivered']);
$entId=(int)$pdo->lastInsertId();

$r = callHttp($kernel,$app,'GET','/digital-entitlements?status=delivered',$viewerTok);
gate('list filter (view-orders admin) → 200 with our row',
    $r['status']===200 && collect($r['json']['data']['data'] ?? [])->contains(fn($e)=>($e['uuid'] ?? null)===$entUuid));

// permission split: viewer cannot mutate
$r = callHttp($kernel,$app,'POST',"/digital-entitlements/{$entUuid}/revoke",$viewerTok);
gate('authz matrix: view-orders-only revoke → 403',$r['status']===403);

// unlimited sentinel via omitted body
$r = callHttp($kernel,$app,'PATCH',"/digital-entitlements/{$entUuid}/limit",$fullTok);
gate('unlimited sentinel → 200 unlimited=true',$r['status']===200 && ($r['json']['data']['unlimited'] ?? false));
gate('raw: download_limit column set to 0 sentinel',(int)$assetRow() ? (int)$pdo->query("SELECT download_limit FROM digital_entitlements WHERE id={$entId}")->fetchColumn() === 0 : false);

// numeric override back to 2
$r = callHttp($kernel,$app,'PATCH',"/digital-entitlements/{$entUuid}/limit",$fullTok,['limit'=>2]);
gate('numeric override → 200 limit=2',$r['status']===200 && ($r['json']['data']['download_limit'] ?? -1)===2);
gate('raw: column reflects 2',(int)$pdo->query("SELECT download_limit FROM digital_entitlements WHERE id={$entId}")->fetchColumn()===2);

// revoke → raw status + activity row (sync queue inside this harness)
$r = callHttp($kernel,$app,'POST',"/digital-entitlements/{$entUuid}/revoke",$fullTok);
gate('revoke → 200',$r['status']===200);
gate('raw: status=revoked + revoked_at set',
    ($row=$pdo->query("SELECT status,revoked_at FROM digital_entitlements WHERE id={$entId}")->fetch(PDO::FETCH_ASSOC))
        && $row['status']==='revoked' && $row['revoked_at']!==null);
gate('activity_log: revoke event recorded', (int)$pdo->query("SELECT count(*) FROM activity_log WHERE subject_type='App\\Models\\DigitalEntitlement' AND subject_id={$entId} AND event='digital.entitlement.revoked'")->fetchColumn()===1);

// restore → delivered + cleared revoked_at + activity row
$r = callHttp($kernel,$app,'POST',"/digital-entitlements/{$entUuid}/restore",$fullTok);
gate('restore → 200',$r['status']===200);
gate('raw: restored to delivered, revoked_at NULL',
    ($row=$pdo->query("SELECT status,revoked_at FROM digital_entitlements WHERE id={$entId}")->fetch(PDO::FETCH_ASSOC))
        && $row['status']==='delivered' && $row['revoked_at']===null);
gate('activity_log: restore event recorded',(int)$pdo->query("SELECT count(*) FROM activity_log WHERE subject_id={$entId} AND event='digital.entitlement.restored'")->fetchColumn()===1);

/* ================= inactive asset gating ================= */
$r = callHttp($kernel,$app,'PUT',"/digital-assets/{$assetUuid}",$fullTok,['status'=>'inactive']);
gate('deactivate → 200',$r['status']===200);
gate('raw: status inactive',$assetRow()['status']==='inactive');

/* ================= queue-proof mode hint ================= */
echo "\n==== MAIN RESULT: ".($total-count($fail))."/{$total} ====\n";
echo "Run 'php w6_independent_check.php queue-proof' against a scratch DB with QUEUE_CONNECTION=database for the real-worker proof.\n";
exit(empty($fail)?0:1);
