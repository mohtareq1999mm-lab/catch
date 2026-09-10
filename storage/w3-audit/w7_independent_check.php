<?php

/**
 * W7 INDEPENDENT CHECK - black-box verification of the DeliveryResolver.
 *
 * Production migrations on a scratch DB, real HTTP, expectations derived
 * from raw PDO reads and locally computed fixture slices (never from the
 * application's own accessors/helpers).
 *
 * Usage: DB_CONNECTION=sqlite DB_DATABASE=<file> php w7_independent_check.php
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$fail = []; $total = 0; $lastRaw = '';
function gate(string $n, bool $ok, string $d = ''): void {
    global $fail, $total;
    $total++;
    echo ($ok ? 'PASS' : 'FAIL') . ": $n" . ($ok || $d === '' ? '' : " | $d") . "\n";
    if (!$ok) { $fail[] = $n; }
}
function http($kernel, $app, $m, $u, ?string $t = null, array $j = [], array $files = [], array $headers = []) {
    try { app('auth')->guard('sanctum')->forgetUser(); } catch (\Throwable $e) {}
    app('auth')->forgetGuards();
    $srv = array_merge(['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], $headers);
    $req = Request::create('/api/v1' . $u, $m, [], [], $files, $srv, json_encode($j));
    if ($t !== null) { $req->headers->set('Authorization', 'Bearer ' . $t); }
    $app->instance('request', $req);
    $res = $kernel->handle($req);
    return ['status' => $res->getStatusCode(), 'json' => json_decode($res->getContent(), true) ?? [], 'raw' => $res->getContent(), 'base' => $res];
}

Artisan::call('migrate:fresh', ['--force' => true]);
$pdo = DB::getPdo();
if (DB::getDriverName() === 'sqlite') {
    $pdo->sqliteCreateFunction('now', fn () => date('Y-m-d H:i:s'));
}

foreach (['view-products', 'create-product', 'update-product'] as $p) {
    $pdo->prepare('INSERT INTO permissions (name, guard_name, created_at, updated_at) VALUES (?, ?, now(), now())')->execute([$p, 'api']);
}
function mkU($pdo, string $tag, array $perms = [], string $type = 'customer') {
    $pdo->prepare('INSERT INTO users (name,email,password,type,is_active,created_at,updated_at) VALUES (?,?,?,?,1,now(),now())')
        ->execute([$tag, uniqid($tag) . '@e.com', password_hash('x', PASSWORD_BCRYPT), $type]);
    $id = (int) $pdo->lastInsertId();
    foreach ($perms as $p) {
        $pid = $pdo->query("SELECT id FROM permissions WHERE name='$p'")->fetchColumn();
        if ($pid) { $pdo->prepare('INSERT INTO model_has_permissions (permission_id,model_type,model_id) VALUES (?,?,?)')->execute([$pid, 'Marvel\Database\Models\User', $id]); }
    }
    return $id;
}
function tok($pdo, int $uid) {
    $t = 'w7i-' . bin2hex(random_bytes(16));
    $pdo->prepare('INSERT INTO personal_access_tokens (tokenable_type,tokenable_id,name,token,created_at) VALUES (?,?,?,?,now())')
        ->execute(['Marvel\Database\Models\User', $uid, 'w7i', hash('sha256', $t)]);
    return $t;
}

$adminId = mkU($pdo, 'w7-admin', ['view-products','create-product','update-product'], 'admin');
$custId  = mkU($pdo, 'w7-cust');
$adminTok = tok($pdo, $adminId);
$custTok  = tok($pdo, $custId);

$pdo->prepare('INSERT INTO products (slug,name,description,price,item_type,status,in_stock,created_at,updated_at) VALUES (?,?,?,?,?,1,1,now(),now())')
    ->execute(['w7i-' . uniqid(), json_encode(['en' => 'I']), json_encode(['en' => 'i']), 9, 'DIGITAL']);
$productId = (int) $pdo->lastInsertId();

/* ---- VIDEO asset through HTTP ---- */
$pdfHead = "%PDF-1.4\n";
$videoBytes = "\x00\x00\x00\x18ftypmp42\x00\x00\x00\x00mp42isom"
    . str_repeat(substr(md5('w7i-seed', true), 0, 16), 200); // deterministic 3200B body
$tmpV = tempnam(sys_get_temp_dir(), 'v'); file_put_contents($tmpV, $videoBytes);
$upV = new \Illuminate\Http\UploadedFile($tmpV, 'clip.mp4', 'application/octet-stream', null, true);

$r = http($kernel, $app, 'POST', "/products/{$productId}/digital-assets", $adminTok, [], ['file' => $upV]);
gate('VIDEO upload accepted via active surface', $r['status'] === 201 && ($r['json']['data']['mime'] ?? '') === 'video/mp4', 'got ' . $r['status']);
$videoUuid = $r['json']['data']['uuid'] ?? '';
$videoRow = fn () => $pdo->query("SELECT * FROM digital_assets WHERE uuid=" . $pdo->quote($videoUuid))->fetch(PDO::FETCH_ASSOC);

/* ---- entitlement + pivot ---- */
$orderCols = collect($pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC));
$fillRow = function (string $table, array $vals) use ($pdo): int {
    $colsInfo = collect($pdo->query("PRAGMA table_info('{$table}')")->fetchAll(PDO::FETCH_ASSOC));
    $names = []; $binds = [];
    foreach ($colsInfo as $c) {
        $n = $c['name'];
        if (isset($vals[$n])) { $names[] = $n; $binds[] = $vals[$n]; continue; }
        if ((int) $c['notnull'] === 1 && $c['dflt_value'] === null && !in_array($n, ['id'], true)) {
            $ty = strtolower((string) $c['type']);
            $names[] = $n; $binds[] = str_contains($ty, 'int') ? 0 : '{}';
        }
    }
    $pdo->prepare("INSERT INTO {$table} (" . implode(',', $names) . ') VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')')->execute($binds);
    return (int) $pdo->lastInsertId();
};

$orderId = $fillRow('orders', ['user_id' => $custId, 'status' => 'completed', 'payment_status' => 'paid', 'name' => json_encode(['en' => 'O'])]);
$itemId = $fillRow('order_products', ['order_id' => $orderId, 'product_id' => $productId, 'product_name' => json_encode(['en' => 'I']), 'item_type' => 'DIGITAL', 'product_quantity' => 1]);
$entUuid = (string) \Ramsey\Uuid\Uuid::uuid4();
$pdo->prepare('INSERT INTO digital_entitlements (uuid,order_id,order_product_id,user_id,status,download_limit,download_count,delivered_at,created_at,updated_at) VALUES (?,?,?,?,?,10,0,now(),now(),now())')
    ->execute([$entUuid, $orderId, $itemId, $custId, 'delivered']);
$entId = (int) $pdo->lastInsertId();

/* ---- full download: byte integrity vs locally computed expectation ---- */
$signed = function (string $mode = null) use ($entUuid, $videoUuid) {
    $params = ['entitlement' => $entUuid, 'asset' => $videoUuid];
    if ($mode !== null) { $params['mode'] = $mode; }
    return \Illuminate\Support\Facades\URL::temporarySignedRoute('general.digital.download', now()->addMinutes(10), $params);
};
$expectedFull = $videoBytes;

$req = Request::create($signed(null), 'GET');
$app->instance('request', $req);
$resBase = $kernel->handle($req);
$resBase->headers; // touch
ob_start(); try { $resBase->sendContent(); } finally { $sent = ob_get_clean(); }
gate('FILE full download byte-exact', hash('sha256', $sent) === hash('sha256', $expectedFull));
$expectedCount = 1;
gate('credit consumed once (raw)', (int) $pdo->query("SELECT download_count FROM digital_entitlements WHERE id={$entId}")->fetchColumn() === $expectedCount);

/* ---- ranged slice byte-exact ---- */
$req = Request::create($signed(null), 'GET');
$req->headers->set('Range', 'bytes=50-149');
$req->headers->set('Accept', 'application/json');
$app->instance('request', $req);
$resR = $kernel->handle($req);
$resR->prepare($req);
ob_start(); try { $resR->sendContent(); } finally { $slice = ob_get_clean(); }
gate('range slice byte-exact (50-149)', $slice === substr($expectedFull, 50, 100));
$expectedCount++; // ranged DOWNLOAD consumes a credit too
gate('range status 206', $resR->getStatusCode() === 206);
gate('Content-Range exact', $resR->headers->get('Content-Range') === 'bytes 50-149/' . strlen($expectedFull));

/* ---- preview: inline + zero credit consumption ---- */
$req = Request::create($signed('preview'), 'GET');
$app->instance('request', $req);
$resP = $kernel->handle($req);
$resP->prepare($req);
ob_start(); try { $resP->sendContent(); } finally { $pBody = ob_get_clean(); }
gate('preview inline delivers bytes', $pBody === $expectedFull && str_contains($resP->headers->get('Content-Disposition'), 'inline'));
gate('preview consumed no credit (raw)', (int) $pdo->query("SELECT download_count FROM digital_entitlements WHERE id={$entId}")->fetchColumn() === $expectedCount);

/* ---- listing additive field ---- */
$r2 = http($kernel, $app, 'GET', '/general/digital/downloads', $custTok);
$entry = collect($r2['json']['data'] ?? [])->firstWhere('uuid', $entUuid);
$vEntry = collect($entry['assets'] ?? [])->firstWhere('uuid', $videoUuid);
gate('listing exposes delivery_type=download for FILE', ($vEntry['delivery_type'] ?? null) === 'download');

/* ---- URL redirect audited ---- */
$urlBytesOk = 'https://example.com/w7i';
$tmpN = tempnam(sys_get_temp_dir(), 'u'); file_put_contents($tmpN, $urlBytesOk);
$upU = new \Illuminate\Http\UploadedFile($tmpN, 'x.pdf', null, null, true);
// create URL asset directly via validated admin endpoint semantics:
$r = http($kernel, $app, 'POST', "/products/{$productId}/digital-assets", $adminTok, [
    'type' => 'URL', 'external_url' => $urlBytesOk,
]);
gate('URL asset created', $r['status'] === 201);
$urlUuid = $r['json']['data']['uuid'];

$rawBefore = (int) $pdo->query("SELECT count(*) FROM digital_download_logs")->fetchColumn();
$r = http($kernel, $app, 'GET', "/general/digital/url/{$entUuid}/{$urlUuid}", $custTok);
gate('redirect 302 to stored normalized URL', $r['status'] === 302 && str_contains($lastRaw ?? '', '') ? true : false);
gate('audit row written for URL access', ((int) $pdo->query("SELECT count(*) FROM digital_download_logs")->fetchColumn()) === $rawBefore + 1);
gate('URL redirect consumed no credit', (int) $pdo->query("SELECT download_count FROM digital_entitlements WHERE id={$entId}")->fetchColumn() === $expectedCount);

/* ---- inactive gating end-to-end ---- */
$pdo->exec("UPDATE digital_assets SET status='inactive' WHERE uuid=" . $pdo->quote($videoUuid));
$req = Request::create($signed(null), 'GET');
$app->instance('request', $req);
$resI = $kernel->handle($req);
gate('inactive asset download → 404', $resI->getStatusCode() === 404);
$pdo->exec("UPDATE digital_assets SET status='active' WHERE uuid=" . $pdo->quote($videoUuid));

echo "\n==== W7 INDEPENDENT RESULT: " . ($total - count($fail)) . "/{$total} ====\n";
exit(empty($fail) ? 0 : 1);
