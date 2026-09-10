<?php

use Marvel\Database\Models\Tag;
use Marvel\Database\Models\User;
use Spatie\Permission\PermissionRegistrar;

// =====================================================================
// PHASE 9 — AUTH PRIMITIVES (roles, users, tokens) + PUBLIC BASE
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 9 — AUTH PRIMITIVES');

// roles via raw DB (spatie schema: roles / role_has_permissions / model_has_roles)
$permId = fn (string $name) => (int) DB::table('permissions')->where('name', $name)->value('id');

$now = now();
DB::table('roles')->insertOrIgnore([
    ['id' => 9001, 'name' => 'zt-content-viewer', 'display_name' => 'ZT Content Viewer', 'guard_name' => 'api', 'created_at' => $now, 'updated_at' => $now],
    ['id' => 9002, 'name' => 'zt-plain', 'display_name' => 'ZT Plain', 'guard_name' => 'api', 'created_at' => $now, 'updated_at' => $now],
]);
DB::table('role_has_permissions')->insert([
    ['permission_id' => $permId('view-content-pages'), 'role_id' => 9001],
    ['permission_id' => $permId('view-sections'), 'role_id' => 9001],
    ['permission_id' => $permId('view-section-types'), 'role_id' => 9001],
]);
// zt-plain deliberately gets NO module permissions

$makeUser = function (string $name, string $email) use ($now): int {
    $u = User::create([
        'name' => $name,
        'email' => $email,
        'password' => bcrypt('password'),
        'is_active' => 1,
        'email_verified_at' => $now,
    ]);
    return (int) $u->id;
};

$viewerId = $makeUser('ZT Viewer', 'zt-viewer@meem.test');
$plainId = $makeUser('ZT Plain', 'zt-plain@meem.test');
$adminId = $makeUser('ZT Admin', 'zt-admin@meem.test');

DB::table('model_has_roles')->insert([
    ['role_id' => 9001, 'model_type' => 'Marvel\Database\Models\User', 'model_id' => $viewerId],
    ['role_id' => 9002, 'model_type' => 'Marvel\Database\Models\User', 'model_id' => $plainId],
    ['role_id' => 2, 'model_type' => 'Marvel\Database\Models\User', 'model_id' => $adminId],
]);

app(PermissionRegistrar::class)->forgetCachedPermissions();

$GLOBALS['viewToken'] = User::find($viewerId)->createToken('zt-viewer')->plainTextToken;
$GLOBALS['plainToken'] = User::find($plainId)->createToken('zt-plain')->plainTextToken;
$GLOBALS['adminToken'] = User::find($adminId)->createToken('zt-admin')->plainTextToken;

$viewer = DB::table('users')->where('id', $viewerId)->first();
$viewerRole = DB::table('model_has_roles')->where('model_id', $viewerId)->value('role_id');
$viewerPerms = collect(explode(',', DB::table('role_has_permissions')->where('role_id', $viewerRole)->pluck('permission_id')->map(fn ($id) => DB::table('permissions')->where('id', $id)->value('name'))->implode(',')))->sort()->values()->all();
$plainPerms = DB::table('role_has_permissions')->where('role_id', 9002)->count();
ev('  viewer id=' . $viewerId . ' email=' . $viewer->email . ' perms=[' . implode(',', $viewerPerms) . ']');
ev('  plain id=' . $plainId . ' perms count=' . $plainPerms);
ev('  admin id=' . $adminId . ' (super_admin role)');
record('TC-AUTH-001', $viewerPerms === ['view-content-pages', 'view-section-types', 'view-sections'], 'viewer role grants exactly the 3 view permissions');
record('TC-AUTH-002', $plainPerms === 0, 'plain role grants ZERO content permissions');

[$c, $j] = http('GET', '/api/v1/me', null, $GLOBALS['viewToken']);
record('TC-AUTH-003', $c === 200 && isset($j['data']['id']), 'viewer token authenticates (GET /me HTTP=' . $c . ')');
[$c] = http('GET', '/api/v1/me', null, $GLOBALS['plainToken']);
[$c2] = http('GET', '/api/v1/me', null, $GLOBALS['adminToken']);
record('TC-AUTH-004', $c === 200 && $c2 === 200, 'plain + admin tokens authenticate');

// =====================================================================
// PUBLIC BASE: note page + Home Decor tag
// =====================================================================
[$c, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Note', 'ar' => 'ملاحظة']], $GLOBALS['adminToken']);
record('TC-BASE-001', $c === 201 && row('content_pages', $j['data']['id'])->slug === 'note', 'note page created (slug=note) for public index');

Tag::create(['name' => ['en' => 'Home Decor', 'ar' => 'ديكور المنزل'], 'slug' => 'home-decor']);
record('TC-BASE-002', DB::table('tags')->where('slug', 'home-decor')->exists(), 'Home Decor tag persisted in DB');

[$c, $j] = http('GET', '/api/v1/general/content-pages');
$slugs = array_column($j['data'] ?? [], 'slug');
record('TC-BASE-003', $c === 200 && in_array('note', $slugs, true), 'public index returns note page');