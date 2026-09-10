<?php

// =====================================================================
// PHASE 1 — ENVIRONMENT / TEST DB PROOF + BASELINE
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 1 — ENVIRONMENT / REAL TEST DB');

ev('APP_ENV            = ' . app()->environment());
ev('DB_CONNECTION      = ' . config('database.default'));
ev('DB_DATABASE        = ' . DB::connection()->getDatabaseName());
ev('DB_DRIVER          = ' . DB::connection()->getDriverName());
ev('CACHE_DRIVER       = ' . config('cache.default'));
ev('QUEUE_CONNECTION   = ' . config('queue.default'));
ev('SESSION_DRIVER     = ' . config('session.driver'));

$dbPath = DB::connection()->getDatabaseName();
$safe = (app()->environment() === 'testing')
    && str_contains($dbPath, 'zerotrust.sqlite')
    && !str_contains($dbPath, 'database.sqlite');
record('TC-ENV-001', $safe, 'real isolated test DB path=' . $dbPath . ' (production database.sqlite NOT in use)');

ev('  migrations run against ' . $dbPath . ' via: php artisan migrate:fresh --force');
$tblCheck = collect(['content_pages', 'sections', 'section_types', 'section_type_settings', 'permissions', 'roles', 'model_has_permissions', 'model_has_roles'])
    ->every(fn ($t) => Schema::hasTable($t));
record('TC-ENV-002', $tblCheck, 'all required tables exist (pages/sections + spatie permission tables)');

ev('');
ev('BASELINE COUNTS');
$baseline = snap($GLOBALS['tables']);
foreach ($baseline as $t => $c) {
    ev('  ' . str_pad($t, 24) . ' = ' . $c);
}
$allZero = collect($baseline)->every(fn ($c) => $c === 0);
record('TC-BASE-001', $allZero, 'baseline all-zero on fresh isolated DB');

// ---- rate limiter introspection (real values, before override) ----
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
        foreach (is_array($limits) ? $limits : [$limits] as $d) {
            $k = $d->key ?? '(none)';
            ev('  ' . $limiterName . ' = ' . $d->maxAttempts . '/min key=' . (is_object($k) ? get_class($k) : $k));
        }
    } catch (Throwable $e) {
        ev('  ' . $limiterName . ' = error: ' . $e->getMessage());
    }
}
record('TC-RL-001', true, 'limiters introspected at runtime (see log)');

// Harness-only ceiling raise (documented; real values recorded above).
Illuminate\Support\Facades\RateLimiter::for('api', fn ($r) => Illuminate\Cache\RateLimiting\Limit::perMinute(100000));
Illuminate\Support\Facades\RateLimiter::for('public-api', fn ($r) => Illuminate\Cache\RateLimiting\Limit::perMinute(100000));
Illuminate\Support\Facades\RateLimiter::for('admin', fn ($r) => Illuminate\Cache\RateLimiting\Limit::perMinute(100000));

// =====================================================================
// PHASE 2 — SETUP (users, permissions, tokens, types via API)
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

// DB proof: permissions + roles exist
$permDbCount = DB::table('permissions')->whereIn('name', $permNames)->count();
$roleDbCount = DB::table('roles')->whereIn('name', [RoleEnum::EDITOR, RoleEnum::SUPER_ADMIN])->count();
record('TC-SET-PERM-DB', $permDbCount === 12 && $roleDbCount === 2, 'permissions=' . $permDbCount . ' roles=' . $roleDbCount . ' exist in DB');

$adminUser = User::create([
    'name' => 'Zerotrust Admin', 'email' => 'zt.admin@example.com', 'email_verified_at' => now(),
    'password' => bcrypt('Password123!'), 'phone_number' => '01000000011', 'is_active' => true,
]);
$adminUser->givePermissionTo($permNames);
$adminUser->assignRole(RoleEnum::SUPER_ADMIN);
$adminToken = $adminUser->createToken('zt-audit')->plainTextToken;
ev('  admin user id=' . $adminUser->id);

$viewUser = User::create([
    'name' => 'Zerotrust Viewer', 'email' => 'zt.viewer@example.com', 'email_verified_at' => now(),
    'password' => bcrypt('Password123!'), 'phone_number' => '01000000012', 'is_active' => true,
]);
$viewUser->givePermissionTo([Perm::VIEW_CONTENT_PAGES, Perm::VIEW_SECTIONS, Perm::VIEW_SECTION_TYPES]);
$viewToken = $viewUser->createToken('zt-audit')->plainTextToken;

$plainUser = User::create([
    'name' => 'Zerotrust Plain', 'email' => 'zt.plain@example.com', 'email_verified_at' => now(),
    'password' => bcrypt('Password123!'), 'phone_number' => '01000000013', 'is_active' => true,
]);
$plainToken = $plainUser->createToken('zt-audit')->plainTextToken;

// DB proof: permission/user pivot + role/user pivot rows exist
$viewPermPivot = DB::table('model_has_permissions')->where('model_id', $viewUser->id)->count();
$adminRolePivot = DB::table('model_has_roles')->where('model_id', $adminUser->id)->count();
record('TC-SET-PIVOT-DB', $viewPermPivot === 3 && $adminRolePivot === 1, 'viewer permission pivot=' . $viewPermPivot . ' admin role pivot=' . $adminRolePivot);

// seed section types through the real API
$seededTypes = ['banners', 'sliders', 'promotions', 'tags', 'categories', 'products', 'flash-sales', 'brands', 'coupons'];
$typeCodes = [];
foreach ($seededTypes as $type) {
    [$code] = http('POST', '/api/v1/section-types', ['type' => $type], $adminToken);
    $typeCodes[$type] = $code;
}
ev('  section-type seed via API: ' . json_encode($typeCodes));
$typeRows = DB::table('section_types')->orderBy('id')->get(['id', 'type', 'created_at', 'updated_at']);
foreach ($typeRows as $tr) {
    ev('  section_types DB row id=' . $tr->id . ' type=' . $tr->type);
}
record('TC-SET-TYPES-DB', DB::table('section_types')->count() === 9 && collect($typeCodes)->every(fn ($c) => $c === 200), '9 types via API, 9 rows in DB');