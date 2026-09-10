<?php

// =====================================================================
// PHASE 0 — ENVIRONMENT / TEST DATABASE PROOF
// =====================================================================
ev('=================================================================');
ev('PHASE 0 — ENVIRONMENT / TEST DATABASE PROOF');
ev('APP_ENV            = ' . app()->environment());
ev('DB_CONNECTION      = ' . config('database.default'));
ev('DB_DATABASE        = ' . DB::connection()->getDatabaseName());
ev('DB_DRIVER          = ' . DB::connection()->getDriverName());
ev('CACHE_DRIVER       = ' . config('cache.default'));
ev('QUEUE_CONNECTION   = ' . config('queue.default'));
ev('SESSION_DRIVER     = ' . config('session.driver'));

$dbPath = DB::connection()->getDatabaseName();
$safe = (app()->environment() === 'testing') && str_contains($dbPath, 'realtest.sqlite');
record('TC-ENV-001', $safe, 'real test DB path=' . $dbPath);

// ---- Rate limiter introspection (real registered limits) ----
ev('');
ev('Rate limiters registered (real values):');
foreach (['api', 'public-api', 'admin'] as $limiterName) {
    try {
        $limiter = Illuminate\Support\Facades\RateLimiter::limiter($limiterName);
        if ($limiter === null) {
            ev('  ' . $limiterName . ' = NOT REGISTERED');
            continue;
        }
        $limits = $limiter(Illuminate\Http\Request::create('/probe', 'GET'));
        $desc = is_array($limits) ? $limits : [$limits];
        foreach ($desc as $d) {
            $k = $d->key;
            $att = isset($k) ? (is_object($k) ? get_class($k) : $k) : '(none)';
            ev('  ' . $limiterName . ' = ' . $d->maxAttempts . '/min key=' . $att);
        }
    } catch (Throwable $e) {
        ev('  ' . $limiterName . ' = error: ' . $e->getMessage());
    }
}

// Harness override: the real app limits admin/API at 60-400 req/min per user/IP.
// This matrix performs ~300 sequential requests in one process (faster than a
// real minute). We preserve the real limiter VALUES as verified facts above,
// then raise the ceiling so the run tests LOGIC (validation/authz/CRUD) not
// throttling. NOT a product change: purely a test-harness environment tweak.
Illuminate\Support\Facades\RateLimiter::for('api', fn ($request) => Illuminate\Cache\RateLimiting\Limit::perMinute(100000));
Illuminate\Support\Facades\RateLimiter::for('public-api', fn ($request) => Illuminate\Cache\RateLimiting\Limit::perMinute(100000));
Illuminate\Support\Facades\RateLimiter::for('admin', fn ($request) => Illuminate\Cache\RateLimiting\Limit::perMinute(100000));

// =====================================================================
// PHASE 1 — BASELINE SNAPSHOT
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 1 — BASELINE DATABASE SNAPSHOT');
$baselineTables = [
    'content_pages', 'sections', 'section_types', 'section_type_settings',
    'products', 'categories', 'brands', 'banners', 'sliders',
    'promotions', 'tags', 'flash_sales', 'coupons',
];
$baseline = snap($baselineTables);
foreach ($baseline as $t => $c) {
    ev('  ' . $t . ' = ' . $c);
}
$baselineCacheKeys = [];
try {
    $baselineCacheKeys = Cache::tags(['content_pages'])->getKeys();
} catch (Throwable $e) {
    $baselineCacheKeys = ['(array store: getKeys unsupported)'];
}
ev('  cache[content_pages] initial = ' . json_encode($baselineCacheKeys));

// =====================================================================
// PHASE 2 — SETUP (permissions, users, tokens, seeded SectionTypes)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 2 — SETUP');

use Marvel\Enums\Permission as Perm;
use Marvel\Enums\Role as RoleEnum;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Role;
use Spatie\Permission\Models\Permission;

$permNames = [
    Perm::VIEW_CONTENT_PAGES, Perm::CREATE_CONTENT_PAGES, Perm::UPDATE_CONTENT_PAGES, Perm::DELETE_CONTENT_PAGES,
    Perm::VIEW_SECTIONS, Perm::CREATE_SECTIONS, Perm::UPDATE_SECTIONS, Perm::DELETE_SECTIONS,
    Perm::VIEW_SECTION_TYPES, Perm::CREATE_SECTION_TYPES, Perm::UPDATE_SECTION_TYPES, Perm::DELETE_SECTION_TYPES,
];
foreach ($permNames as $p) {
    Permission::firstOrCreate(['name' => $p, 'guard_name' => 'api']);
}
Role::firstOrCreate(['name' => RoleEnum::EDITOR, 'guard_name' => 'api', 'display_name' => ['en' => 'Editor', 'ar' => 'محرر']]);
Role::firstOrCreate(['name' => RoleEnum::SUPER_ADMIN, 'guard_name' => 'api', 'display_name' => ['en' => 'Super Admin', 'ar' => 'مدير النظام']]);

$adminUser = User::create([
    'name' => 'CMS Administrator',
    'email' => 'admin.realworld@example.com',
    'email_verified_at' => now(),
    'password' => bcrypt('Password123!'),
    'phone_number' => '01000000001',
    'is_active' => true,
]);
$adminUser->givePermissionTo($permNames);
$adminUser->assignRole(RoleEnum::SUPER_ADMIN);
$adminToken = $adminUser->createToken('realworld-audit')->plainTextToken;
ev('  admin user id=' . $adminUser->id . ' token=***' . substr($adminToken, -4));

$viewUser = User::create([
    'name' => 'View Only User',
    'email' => 'view.realworld@example.com',
    'email_verified_at' => now(),
    'password' => bcrypt('Password123!'),
    'phone_number' => '01000000002',
    'is_active' => true,
]);
$viewUser->givePermissionTo([Perm::VIEW_CONTENT_PAGES, Perm::VIEW_SECTIONS, Perm::VIEW_SECTION_TYPES]);
$viewToken = $viewUser->createToken('realworld-audit')->plainTextToken;

$plainUser = User::create([
    'name' => 'Plain User',
    'email' => 'plain.realworld@example.com',
    'email_verified_at' => now(),
    'password' => bcrypt('Password123!'),
    'phone_number' => '01000000003',
    'is_active' => true,
]);
$plainToken = $plainUser->createToken('realworld-audit')->plainTextToken;

$seededTypes = ['banners', 'sliders', 'promotions', 'tags', 'categories', 'products', 'flash-sales', 'brands', 'coupons'];
$typeCreated = [];
foreach ($seededTypes as $type) {
    [$code] = http('POST', '/api/v1/section-types', ['type' => $type], $adminToken);
    $typeCreated[$type] = $code;
}
ev('  section-type seed via API: ' . json_encode($typeCreated));
$typeRows = DB::table('section_types')->orderBy('id')->get(['id', 'type']);
foreach ($typeRows as $tr) {
    ev('  section_types row id=' . $tr->id . ' type=' . $tr->type);
}

// a custom non-seeded type for CRUD testing
[$code, $json] = http('POST', '/api/v1/section-types', ['type' => 'new-arrivals'], $adminToken);
ev('  custom type create via API: HTTP=' . $code);
$customType = DB::table('section_types')->where('type', 'new-arrivals')->first();
ev('  custom type DB row: ' . json_encode($customType));
record('TC-ST-CUSTOM-CREATE', $code === 200 && $customType !== null, 'HTTP=' . $code . ' type=new-arrivals');