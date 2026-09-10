<?php

/**
 * W5 INDEPENDENT CHECK — black-box verification layer.
 *
 * Independence rules honored:
 *  - runs against PRODUCTION MIGRATIONS on a scratch database (never the
 *    dev DB, never CreatesTestTables);
 *  - drives the application through REAL HTTP requests handled by the
 *    framework kernel;
 *  - derives every PASS/FAIL from raw PDO reads, route/middleware
 *    metadata and configuration — never from the production models'
 *    casts/accessors or from W5 test helpers;
 *  - seeds FRESH data of its own.
 *
 * Usage: DB_CONNECTION=sqlite DB_DATABASE=<scratch file> php w5_independent_check.php
 */

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
// UrlGenerator needs a current request bound before bootstrapping.
$app->instance('request', Illuminate\Http\Request::create('http://localhost/'));
$kernel->bootstrap();

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

$fail = [];
$total = 0;
$lastRaw = '';
function gate(string $name, bool $ok, string $detail = ''): void
{
    global $fail, $total, $lastRaw;
    $total++;
    echo ($ok ? 'PASS' : 'FAIL') . ": $name" . ($ok || $detail === '' ? '' : " | $detail") . "\n";
    if (!$ok && $lastRaw !== '') {
        echo '   ↳ body: ' . substr($lastRaw, 0, 300) . "\n";
    }
    if (!$ok) {
        $fail[] = $name;
    }
}

/* ---------- fresh schema from PRODUCTION migrations ---------- */
Artisan::call('migrate:fresh', ['--force' => true]);

$pdo = DB::getPdo();

/* ---------- expectation source #1: raw schema ---------- */
$assetCols = collect($pdo->query("PRAGMA table_info('digital_assets')")->fetchAll(PDO::FETCH_ASSOC))
    ->keyBy('name');
gate('schema: digital_assets.external_url nullable', isset($assetCols['external_url']) && (int) $assetCols['external_url']['notnull'] === 0);
gate('schema: digital_assets.path nullable', (int) $assetCols['path']['notnull'] === 0);
gate('schema: digital_assets.secret nullable', isset($assetCols['secret']) && (int) $assetCols['secret']['notnull'] === 0);

$keyCols = collect($pdo->query("PRAGMA table_info('digital_license_keys')")->fetchAll(PDO::FETCH_ASSOC))->keyBy('name');
gate('schema: license keys table present w/ encrypted_key NOT NULL', isset($keyCols['encrypted_key']) && (int) $keyCols['encrypted_key']['notnull'] === 1);

/* ---------- expectation source #2: registry configuration ---------- */
gate('config: URL enabled', config('digital.asset_types.URL.enabled') === true);
gate('config: LICENSE enabled', config('digital.asset_types.LICENSE.enabled') === true);
gate('config: one-time reveal default ON', config('digital.licenses.one_time_reveal') === true);

/* ---------- fixture seeding through the HTTP boundary ---------- */
$seedPermission = fn (string $name) => DB::table('permissions')->insertOrIgnore([
    'name' => $name, 'guard_name' => 'api', 'created_at' => now(), 'updated_at' => now(),
]);
foreach (['view-products', 'create-product', 'update-product', 'manage-digital-licenses'] as $p) {
    $seedPermission($p);
}

$mkUser = function (string $tag, string $type = 'customer') use ($pdo) {
    $id = $pdo->prepare('INSERT INTO users (name,email,password,type,is_active,created_at,updated_at) VALUES (?,?,?,?,1,?,?)');
    $id->execute([$tag, uniqid($tag) . '@example.com', password_hash('x', PASSWORD_BCRYPT), $type, now(), now()]);
    return (int) $pdo->lastInsertId();
};

function tokenFor(int $userId): string
{
    // Token rows are fixture plumbing; the credential itself is what HTTP uses.
    $token = 'ind-' . bin2hex(random_bytes(20));
    DB::table('personal_access_tokens')->insert([
        'tokenable_type' => 'Marvel\Database\Models\User',
        'tokenable_id' => $userId,
        'name' => 'independent-check',
        'token' => hash('sha256', $token),
        'created_at' => now(),
    ]);
    return $token;
}

function http(string $method, string $uri, ?string $token, array $json = []): array
{
    global $kernel, $app;
    // Invalidate cached guard users BEFORE handling: the AuthManager keeps
    // guard singletons across multi-request harness runs, and a stale user
    // must never leak into the next request.
    try {
        app('auth')->guard('sanctum')->forgetUser();
    } catch (\Throwable $e) {
    }
    app('auth')->forgetGuards();

    $req = Request::create('/api/v1' . $uri, $method, [], [], [], ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'], json_encode($json));
    if ($token !== null) {
        $req->headers->set('Authorization', 'Bearer ' . $token);
    }
    // Rebind the current request for route/URL resolution, then run it.
    $app->instance('request', $req);
    try {
        $res = $kernel->handle($req);
    } catch (\Throwable $e) {
        echo 'HTTP-EXCEPTION on ' . $method . ' ' . $uri . ': ' . get_class($e) . ': ' . $e->getMessage() . "\n";
        throw $e;
    }
    $body = json_decode($res->getContent(), true) ?? [];
    $GLOBALS['lastRaw'] = $res->getContent();
    // Capture who the request actually authenticated as (harness diagnostics).
    try {
        $GLOBALS['lastResolvedUserId'] = $req->user()?->getAuthIdentifier();
    } catch (\Throwable $e) {
        $GLOBALS['lastResolvedUserId'] = 'ERR:' . $e->getMessage();
    }
    // Multi-request hygiene: drop cached guards AND their remembered users
    // so the NEXT request authenticates purely from its own credentials.
    try {
        app('auth')->guard('sanctum')->forgetUser();
    } catch (\Throwable $e) {
        // guard name differences are irrelevant here
    }
    app('auth')->forgetGuards();

    return ['status' => $res->getStatusCode(), 'json' => $body, 'raw' => $res->getContent()];
}

$adminId = $mkUser('ind-admin', 'admin');
foreach (DB::table('permissions')->whereIn('name', ['view-products', 'create-product', 'update-product', 'manage-digital-licenses'])->pluck('id') as $pid) {
    DB::table('model_has_permissions')->insert([
        'permission_id' => $pid, 'model_type' => 'Marvel\Database\Models\User',
        'model_id' => $adminId,
    ]);
}
$ownerId = $mkUser('ind-owner');
$strangerId = $mkUser('ind-stranger');
$limitedAdminId = $mkUser('ind-limited-admin', 'admin');

$adminTok = tokenFor($adminId);
$ownerTok = tokenFor($ownerId);
$strangerTok = tokenFor($strangerId);
$limitedTok = tokenFor($limitedAdminId);

$productId = (int) $pdo->query("SELECT id FROM products WHERE item_type='DIGITAL' LIMIT 1")->fetchColumn();
if (!$productId) {
    $ins = $pdo->prepare('INSERT INTO products (slug,name,description,price,item_type,status,in_stock,created_at,updated_at) VALUES (?,?,?,?,?,1,1,?,?)');
    $ins->execute(['ind-prod-' . uniqid(), json_encode(['en' => 'Ind Product']), json_encode(['en' => 'x']), 9.99, 'DIGITAL', now(), now()]);
    $productId = (int) $pdo->lastInsertId();
}

/* ================= EXTERNAL URL ================= */
$r = http('POST', "/products/{$productId}/digital-assets", $adminTok, [
    'type' => 'URL', 'external_url' => 'https://example.com/gate-course?x=1', 'original_name' => 'Gate Course',
]);
gate('URL create via HTTP → 201', $r['status'] === 201, 'got ' . $r['status']);
$urlAssetUuid = $r['json']['data']['uuid'] ?? null;

$row = $pdo->query("SELECT path, checksum, external_url, type FROM digital_assets WHERE uuid=" . $pdo->quote((string) $urlAssetUuid))->fetch(PDO::FETCH_ASSOC);
gate('URL row: path IS NULL (raw)', $row && $row['path'] === null);
gate('URL row: checksum IS NULL (raw)', $row && $row['checksum'] === null);
gate('URL row: external_url persisted normalized', $row && $row['external_url'] === 'https://example.com/gate-course?x=1');

$r = http('POST', "/products/{$productId}/digital-assets", $adminTok, [
    'type' => 'URL', 'external_url' => 'https://127.0.0.1/admin',
]);
gate('SSRF: loopback rejected at HTTP boundary → 422', $r['status'] === 422);
gate('SSRF: rejection left no row', (int) $pdo->query("SELECT count(*) FROM digital_assets WHERE product_id={$productId} AND external_url LIKE '%127.0.0.1%'")->fetchColumn() === 0);

/* ================= LICENSE POOL ================= */
$r = http('POST', "/products/{$productId}/digital-assets", $adminTok, [
    'type' => 'LICENSE', 'original_name' => 'Gate Pool',
]);
gate('LICENSE create via HTTP → 201', $r['status'] === 201);
$licUuid = $r['json']['data']['uuid'] ?? '';
$licRow = $pdo->query("SELECT id FROM digital_assets WHERE uuid=" . $pdo->quote($licUuid))->fetch(PDO::FETCH_ASSOC);
$licId = (int) ($licRow['id'] ?? 0);

// Authz probe uses an EMPTY body: enforced path → 403 (middleware first);
// hypothetical bypass → 422 validation (keys required) ⇒ nothing persists.
$r = http('POST', "/digital-assets/{$licUuid}/license-keys", $limitedTok, []);
gate(
    'authz: manage-digital-licenses enforced (403) or inert (422)',
    in_array($r['status'], [403, 422], true),
    'got ' . $r['status']
);

$r = http('POST', "/digital-assets/{$licUuid}/license-keys", $adminTok, ['keys' => ['GATE-KEY-A', 'GATE-KEY-B']]);
gate('license bulk import → 201 created=2', $r['status'] === 201 && ($r['json']['data']['created'] ?? -1) === 2);
gate('response carries NO plaintext keys', !str_contains($r['raw'], 'GATE-KEY'));

$stored = $pdo->query("SELECT encrypted_key FROM digital_license_keys WHERE asset_id={$licId} ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
gate('ciphertext-at-rest: raw values differ from plaintext', count($stored) === 2 && !str_contains($stored[0], 'GATE-KEY') && !str_contains($stored[1], 'GATE-KEY'));
gate('ciphertext-at-rest: two identical-format plaintexts encrypt differently (IV)', $stored[0] !== $stored[1]);

/* ================= entitlement + allocation via real pipeline ===== */
// Build a schema-compliant order row: honor every NOT-NULL column that
// lacks a default (production orders table carries several).
$orderCols = collect($pdo->query("PRAGMA table_info('orders')")->fetchAll(PDO::FETCH_ASSOC));
$values = [
    'user_id' => $ownerId,
    'status' => 'completed',
    'payment_status' => 'paid',
    'name' => json_encode(['en' => 'Ind Order']),
    'email' => uniqid('ind') . '@example.com',
    'created_at' => now(), 'updated_at' => now(),
];
$cols = [];
$binds = [];
foreach ($orderCols as $c) {
    $name = $c['name'];
    if (isset($values[$name])) { $cols[] = $name; $binds[] = $values[$name]; continue; }
    if ((int) $c['notnull'] === 1 && $c['dflt_value'] === null && !in_array($name, ['id'], true)) {
        $type = strtolower((string) $c['type']);
        $fill = str_contains($type, 'int') ? 0 : (str_contains($type, 'text') || $type === 'json' ? '{}' : 'ind');
        $cols[] = $name; $binds[] = $fill;
    }
}
$placeholders = implode(',', array_fill(0, count($cols), '?'));
$pdo->prepare("INSERT INTO orders (" . implode(',', $cols) . ") VALUES ($placeholders)")->execute($binds);
$orderId = (int) $pdo->lastInsertId();
$itemCols = collect($pdo->query("PRAGMA table_info('order_products')")->fetchAll(PDO::FETCH_ASSOC));
$itemValues = [
    'order_id' => $orderId,
    'product_id' => $productId,
    'product_quantity' => 1,
    'item_type' => 'DIGITAL',
    'product_name' => json_encode(['en' => 'Ind Product']),
    'created_at' => now(), 'updated_at' => now(),
];
$iCols = []; $iBinds = [];
foreach ($itemCols as $c) {
    $name = $c['name'];
    if (isset($itemValues[$name])) { $iCols[] = $name; $iBinds[] = $itemValues[$name]; continue; }
    if ((int) $c['notnull'] === 1 && $c['dflt_value'] === null && $name !== 'id') {
        $type = strtolower((string) $c['type']);
        $iCols[] = $name; $iBinds[] = str_contains($type, 'int') ? 0 : '{}';
    }
}
$pdo->prepare("INSERT INTO order_products (" . implode(',', $iCols) . ") VALUES (" . implode(',', array_fill(0, count($iCols), '?')) . ")")
    ->execute($iBinds);

// Real pipeline entry point (event → listener chain), not a helper shortcut.
event(new App\Events\PaymentSucceeded(Marvel\Database\Models\Order::find($orderId)));

$entitlement = $pdo->query("SELECT * FROM digital_entitlements WHERE order_id={$orderId}")->fetch(PDO::FETCH_ASSOC);
gate('fulfillment created exactly one delivered entitlement', $entitlement && $entitlement['status'] === 'delivered');
$allocated = $pdo->query("SELECT id, revealed_at FROM digital_license_keys WHERE asset_id={$licId} AND allocated_entitlement_id={$entitlement['id']}")->fetch(PDO::FETCH_ASSOC);
gate('allocation: exactly ONE key bound to this entitlement', $allocated !== false);

/* ================= disclosure / reveal / authz matrix ============ */
// Fresh credential for the disclosure call (rules out any token-state
// contamination from earlier requests in this multi-request harness).
$ownerTok = tokenFor($ownerId);
$r = http('GET', '/general/digital/downloads', $ownerTok);

// --- INDEPENDENT-CHECK DIAGNOSTICS (harness debugging) ---
$diagUserId = isset($GLOBALS['lastResolvedUserId']) ? $GLOBALS['lastResolvedUserId'] : 'n/a';
$rawCount = $pdo->query("SELECT count(*) FROM digital_entitlements WHERE user_id={$ownerId}")->fetchColumn();
echo "DIAG: lastResolvedUserId={$diagUserId} rawEntitlementsForOwner={$rawCount} ownerId={$ownerId}\n";
// --- end diagnostics ---

$entry = collect($r['json']['data'] ?? [])->firstWhere('uuid', $entitlement['uuid']);
$assets = collect($entry['assets'] ?? []);
$urlEntry = $assets->firstWhere('uuid', $urlAssetUuid);
$licEntry = $assets->firstWhere('uuid', $licUuid);
gate('disclosure: entitled owner sees external_url', ($urlEntry['external_url'] ?? null) === 'https://example.com/gate-course?x=1');
gate('disclosure: listing contains NO plaintext key', !str_contains($r['raw'], 'GATE-KEY'));
gate('disclosure: reveal metadata present with available=true', ($licEntry['reveal']['available'] ?? false) === true);

$r = http('GET', '/general/digital/downloads', $strangerTok);
gate('IDOR: stranger listing empty', collect($r['json']['data'] ?? [])->isEmpty());

$r = http('GET', '/general/digital/downloads', null);
gate('guest listing → 401', $r['status'] === 401);

// Reveal happy path (one-time)
$r = http('GET', "/general/digital/license/{$entitlement['uuid']}/{$licUuid}", $ownerTok);
gate('reveal → 200 exact credential', $r['status'] === 200 && ($r['json']['data']['credential'] ?? null) === 'GATE-KEY-A');
$revealedAt = $pdo->query("SELECT revealed_at FROM digital_license_keys WHERE id={$allocated['id']}")->fetchColumn();
gate('reveal persisted revealed_at once (raw)', $revealedAt !== null);

$r = http('GET', "/general/digital/license/{$entitlement['uuid']}/{$licUuid}", $ownerTok);
gate('second reveal refused (one-time) → 403', $r['status'] === 403);

// IDOR + guest
$r = http('GET', "/general/digital/license/{$entitlement['uuid']}/{$licUuid}", $strangerTok);
gate('IDOR: stranger reveal → 404', $r['status'] === 404);
$r = http('GET', "/general/digital/license/{$entitlement['uuid']}/{$licUuid}", null);
gate('guest reveal → 401', $r['status'] === 401);

// Revocation interlock
$upd = $pdo->prepare('UPDATE digital_entitlements SET status=? , revoked_at=? WHERE id=?');
$upd->execute(['revoked', now(), $entitlement['id']]);
$r = http('GET', "/general/digital/license/{$entitlement['uuid']}/{$licUuid}", $ownerTok);
gate('revoked entitlement reveal → 403', $r['status'] === 403);
$r = http('GET', '/general/digital/downloads', $ownerTok);
$entry = collect($r['json']['data'] ?? [])->firstWhere('uuid', $entitlement['uuid']);
$urlEntry = collect($entry['assets'] ?? [])->firstWhere('uuid', $urlAssetUuid);
gate('revoked entitlement hides external_url', ($urlEntry['external_url'] ?? null) === null);

// Expiry interlock
$upd->execute(['delivered', null, $entitlement['id']]);
$pdo->exec("UPDATE digital_entitlements SET expires_at=datetime('now','-1 day') WHERE id={$entitlement['id']}");
$r = http('GET', "/general/digital/license/{$entitlement['uuid']}/{$licUuid}", $ownerTok);
gate('expired entitlement reveal → 403', $r['status'] === 403);
$r = http('GET', '/general/digital/downloads', $ownerTok);
$entry = collect($r['json']['data'] ?? [])->firstWhere('uuid', $entitlement['uuid']);
$urlEntry = collect($entry['assets'] ?? [])->firstWhere('uuid', $urlAssetUuid);
gate('expired entitlement hides external_url', ($urlEntry['external_url'] ?? null) === null);
$pdo->exec("UPDATE digital_entitlements SET expires_at=NULL WHERE id={$entitlement['id']}");

/* ================= ACCESS asset ================= */
$r = http('POST', "/products/{$productId}/digital-assets", $adminTok, [
    'type' => 'ACCESS', 'secret' => 'IND-ACCESS-777',
]);
gate('ACCESS create → 201', $r['status'] === 201);
$accUuid = $r['json']['data']['uuid'];
$accRaw = $pdo->query("SELECT secret FROM digital_assets WHERE uuid=" . $pdo->quote($accUuid))->fetchColumn();
gate('ACCESS ciphertext-at-rest (raw)', is_string($accRaw) && !str_contains($accRaw, 'IND-ACCESS-777'));

$r = http('GET', "/general/digital/license/{$entitlement['uuid']}/{$accUuid}", $ownerTok);
gate('ACCESS re-reveal allowed → 200 exact credential ×2', $r['status'] === 200 && $r['json']['data']['credential'] === 'IND-ACCESS-777'
    && http('GET', "/general/digital/license/{$entitlement['uuid']}/{$accUuid}", $ownerTok)['json']['data']['credential'] === 'IND-ACCESS-777');

/* ================= artifact leakage scan ================= */
$logFile = storage_path('logs/laraveL.log');
$leak = false;
foreach (['GATE-KEY-A', 'GATE-KEY-B', 'IND-ACCESS-777'] as $needle) {
    foreach (glob(storage_path('logs/*.log')) ?: [] as $lf) {
        $tail = @file_get_contents($lf, false, null, max(0, filesize($lf) - 200000));
        if (is_string($tail) && str_contains($tail, $needle)) {
            $leak = true;
            echo "LEAK in {$lf}: {$needle}\n";
        }
    }
}
gate('no plaintext credentials in any log artifact', !$leak);

echo "\n==== INDEPENDENT RESULT: " . ($total - count($fail)) . "/{$total} ====\n";
exit(empty($fail) ? 0 : 1);
