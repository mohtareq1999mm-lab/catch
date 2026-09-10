<?php

declare(strict_types=1);

// =====================================================================
// E2E PHASE 1 - environment proof, auth lifecycle, permission matrix,
// public storefront reads, error contracts, rate limiting, translations.
// Run:  php storage/e2e/_e1.php
// =====================================================================

require __DIR__ . '/_hbase.php';

ev('=================================================================');
ev('E2E PHASE 1 - ENVIRONMENT / AUTH / PERMISSIONS / PUBLIC / ERRORS');
ev('=================================================================');
ev('APP_ENV          = ' . app()->environment());
ev('DB_CONNECTION    = ' . config('database.default') . ' / ' . DB::connection()->getDatabaseName());
ev('DB_DRIVER        = ' . DB::connection()->getDriverName());
ev('CACHE_DRIVER     = ' . config('cache.default'));
ev('QUEUE_CONNECTION = ' . config('queue.default'));
ev('BROADCAST_DRIVER = ' . config('broadcast.default'));

record('ENV-001', DB::connection()->getDriverName() === 'mysql' && str_contains(DB::connection()->getDatabaseName(), 'e2e_audit'), 'real MySQL audit database');
record('ENV-002', config('cache.default') === 'redis', 'real Redis cache driver');
record('ENV-003', config('queue.default') === 'database', 'database queue connection');

// Redis round-trip proof (real server, real key)
Cache::store('redis')->put('e2e:probe', 'alive-' . uniqid(), 60);
$probeVal = Cache::store('redis')->get('e2e:probe');
record('ENV-004', is_string($probeVal) && str_starts_with($probeVal, 'alive-'), 'Redis set/get round-trip: ' . $probeVal);

// =====================================================================
// AUTH LIFECYCLE - register -> me -> change password -> logout
// =====================================================================
ev('');
ev('--- AUTH LIFECYCLE ---');

$email = 'e2e.customer.' . time() . '@gmail.com';
[$c, $j] = http('POST', '/api/v1/register', [
    'first_name' => 'E2E',
    'last_name' => 'Customer',
    'email' => $email,
    'password' => 'Password123!',
    'password_confirmation' => 'Password123!',
    'phone_number' => '01' . str_pad((string) random_int(10000000, 99999999), 10, '7'),
    'policy' => 1,
]);
// Real contract: register returns otp_status (no token); /token issues credentials.
$regToken = $j['data']['accessToken'] ?? $j['data']['token'] ?? null;
record('AUTH-001', ($c === 200 || $c === 201) && isset($j['data']['otp_status']), "register HTTP=$c dataKeys=" . implode(',', array_slice(array_keys($j['data'] ?? []), 0, 8)) . (isset($j['errors']) ? ' errors=' . json_encode($j['errors']) : ''));

if ($regToken === null) {
    // Fall back to the canonical token endpoint.
    [$c2, $j2] = http('POST', '/api/v1/token', ['email' => $email, 'password' => 'Password123!']);
    $regToken = $j2['data']['accessToken'] ?? $j2['data']['token'] ?? null;
    ev("  fallback /token HTTP=$c2");
}
record('AUTH-002', is_string($regToken) && strlen($regToken) > 20, 'bearer token obtained via public auth endpoints');

[$c, $j] = http('GET', '/api/v1/me', null, $regToken);
record('AUTH-003', $c === 200 && ($j['data']['email'] ?? null) === $email, "GET /me HTTP=$c email=" . ($j['data']['email'] ?? 'MISSING'));

[$c] = http('POST', '/api/v1/logout', null, $regToken);
record('AUTH-004', $c === 200, "logout HTTP=$c");

[$c, $j] = http('GET', '/api/v1/me', null, $regToken);
record('AUTH-005', $c === 401, "token revoked after logout: GET /me HTTP=$c (expected 401)");

// Admin login against seeded super_admin role path: create admin user directly in audit DB.
use Marvel\Database\Models\User as U;
use Spatie\Permission\Models\Permission as SP;

$admin = U::firstOrCreate(
    ['email' => 'e2e.admin@audit.test'],
    [
        'name' => 'E2E Super Admin', 'password' => bcrypt('Password123!'),
        'email_verified_at' => now(), 'is_active' => true, 'type' => 'admin', 'phone_number' => '01000000222',
    ]
);
$admin->assignRole('super_admin');
foreach (SP::where('guard_name', 'api')->get() as $p) {
    $admin->givePermissionTo($p->name);
}
$GLOBALS['adminToken'] = $admin->createToken('e2e-admin')->plainTextToken;
$GLOBALS['plainUser'] = U::firstOrCreate(
    ['email' => 'e2e.plain@audit.test'],
    [
        'name' => 'E2E Plain', 'password' => bcrypt('Password123!'),
        'email_verified_at' => now(), 'is_active' => true, 'type' => 'user', 'phone_number' => '01000000333',
    ]
);
$GLOBALS['customerToken'] = $GLOBALS['plainUser']->createToken('e2e-customer')->plainTextToken;
ev('  admin id=' . $admin->id . ', plain customer id=' . $GLOBALS['plainUser']->id . ' created in audit DB');

[$c, $j] = http('POST', '/api/v1/admin-login', ['email' => 'e2e.admin@audit.test', 'password' => 'Password123!']);
$adminLoginOk = $c === 200 && isset($j['data']['token']) && count($j['data']['permissions'] ?? []) > 0;
ev('  admin-login HTTP=' . $c . ' tokenKey=' . (isset($j['data']['token']) ? 'yes' : 'no') . ' permissionCount=' . count($j['data']['permissions'] ?? []));
record('AUTH-006', $adminLoginOk, 'admin panel login issues token + permissions payload (keys: ' . implode(',', array_keys($j['data'] ?? [])) . ')');

// =====================================================================
// PERMISSION MATRIX - guest / plain customer / super admin
// =====================================================================
ev('');
ev('--- PERMISSION MATRIX ---');

$matrix = [
    // [method, uri, permission-gated?, expected guest, expected plain]
    ['GET', '/api/v1/brands', false],
    ['GET', '/api/v1/dashboard/overview', true],
    ['GET', '/api/v1/admin/notifications', true],
    ['GET', '/api/v1/settings', true],
];
foreach ($matrix as [$m, $u]) {
    [$cg] = http($m, $u);
    [$cp] = http($m, $u, null, $GLOBALS['customerToken']);
    [$ca] = http($m, $u, null, $GLOBALS['adminToken']);
    $label = "$m $u";
    if ($u === '/api/v1/brands') {
        record('PERM-001', $cg === 401 && $cp === 403 && $ca === 200, "guest=$cg(401) plain=$cp(403 view-brands missing) admin=$ca(200)");
    } else {
        record('PERM-002-' . basename($u), $cg === 401 && $cp === 403 && $ca === 200, "guest=$cg(401) plain=$cp(403) admin=$ca(200) :: $label");
    }
}

// Cashier mark-paid endpoint (fixed in prior pass): permission enforced at boundary.
$orderRow = DB::table('orders')->orderBy('id')->first();
$orderId = $orderRow?->id ?? 0;
[$cg] = http('POST', "/api/v1/general/checkout/cashier/$orderId/mark-paid", [], null);
[$cp] = http('POST', "/api/v1/general/checkout/cashier/$orderId/mark-paid", [], $GLOBALS['customerToken']);
record('PERM-010', $cg === 401 && $cp === 403, "cashier mark-paid guest=$cg(401) plain=$cp(403 update-order-status)");

// =====================================================================
// PUBLIC STOREFRONT READS
// =====================================================================
ev('');
ev('--- PUBLIC STOREFRONT ---');
foreach ([
    ['/api/v1/general/nav-data', 'navData'],
    ['/api/v1/general/categories', 'categories'],
    ['/api/v1/general/products', 'products'],
    ['/api/v1/general/settings', 'settings'],
    ['/api/v1/general/faqs', 'faqs'],
    ['/api/v1/general/site-reviews', 'siteReviews'],
    ['/api/v1/general/currencies', 'currencies'],
    ['/api/v1/enum-types', 'enumTypes'],
] as [$u, $n]) {
    [$c, $j] = http('GET', $u);
    $ok = $c === 200;
    record('PUB-' . strtoupper($n), $ok, "GET $u HTTP=$c" . (!$ok ? ' body=' . substr(json_encode($j), 0, 160) : ''));
}

// Public product detail with related products + pricing enrichment fields present.
$productRow = DB::table('products')->orderBy('id')->first();
if ($productRow) {
    [$c, $j] = http('GET', '/api/v1/general/products/' . $productRow->slug);
    $hasPrice = array_key_exists('current_price', $j['data'] ?? []);
    record('PUB-PRODUCT-DETAIL', $c === 200 && ($j['data']['slug'] ?? null) === $productRow->slug && $hasPrice, "HTTP=$c slug={$productRow->slug} current_price_present=" . var_export($hasPrice, true));
} else {
    record('PUB-PRODUCT-DETAIL', false, 'NO PRODUCTS SEEDED YET - will be covered in phase 2 after product creation');
}

// =====================================================================
// ERROR CONTRACTS - wrong method, unknown resource, invalid payloads
// =====================================================================
ev('');
ev('--- ERROR CONTRACTS ---');
[$c] = http('DELETE', '/api/v1/general/faqs');
record('ERR-405', $c === 405, "DELETE on GET-only collection HTTP=$c");
[$c, $j] = http('GET', '/api/v1/general/products/definitely-not-a-real-slug-' . time());
record('ERR-404', $c === 404, "unknown product slug HTTP=$c msg=" . ($j['message'] ?? ''));
[, , $resp] = httpFull('GET', '/api/v1/general/governorates/notanumber');
record('ERR-404B', $resp->getStatusCode() === 404, 'non-numeric governorate id constrained by whereNumber -> ' . $resp->getStatusCode());

// =====================================================================
// RATE LIMITING - sensitive limiter is 5/min per IP (contact-us).
// =====================================================================
ev('');
ev('--- RATE LIMIT (throttle:sensitive = 5/min per IP, pinned IP) ---');
$codes = [];
for ($i = 0; $i < 7; $i++) {
    [$c] = httpPinnedIp('POST', '/api/v1/contact-us', [
        'name' => "RL Tester $i", 'email' => "rl$i@gmail.com", 'subject' => "rl$i", 'message' => 'rate limit proof message',
    ]);
    $codes[] = $c;
}
$got429 = in_array(429, $codes, true);
$allowed = count(array_filter($codes, fn ($x) => $x < 400));
ev('  contact-us sequence: ' . implode(',', $codes));
record('RATE-001', $got429 && $allowed === 5, "exactly 5 allowed then 429 (limiter=enforced): allowed=$allowed sequence=" . implode(',', $codes));
// Restore limiter window headroom by rotating IP for subsequent scripts.
saveState();
file_put_contents(__DIR__ . '/state.json', json_encode([
    'adminToken' => $GLOBALS['adminToken'],
    'customerToken' => $GLOBALS['customerToken'],
]));
ev('');
ev('PHASE 1 COMPLETE. results so far: ' . count($results));
