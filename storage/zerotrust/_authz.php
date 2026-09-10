<?php

// =====================================================================
// PHASE 14 — AUTHORIZATION MATRIX (guest / viewer / plain / admin)
// + SECURITY extras
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 14 — AUTHORIZATION MATRIX (4 user classes)');

$cpId = (int) DB::table('content_pages')->where('slug', 'electronics')->value('id');
$secId = (int) DB::table('sections')->orderBy('id')->value('id');
$reorderIds = DB::table('sections')->orderBy('id')->pluck('id')->take(2)->all();

// temp targets for destructive rows
[$c, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'AZ Del Page', 'ar' => 'حذف']], $GLOBALS['adminToken']);
$azCp = $j['data']['id'];
[$c, $j] = http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'AZ Del Sec', 'ar' => 'حذف'], 'is_active' => 1], $GLOBALS['adminToken']);
$azSec = $j['data']['id'];
[$c, $j] = http('POST', '/api/v1/section-types', ['type' => 'az-del'], $GLOBALS['adminToken']);

// route list: [label, method, uri, payload, expected-by-class, isWrite]
$matrix = [
    ['GET /content-pages', 'GET', '/api/v1/content-pages', null, [401, 200, 403, 200]],
    ['GET /content-pages/{id}', 'GET', '/api/v1/content-pages/' . $cpId, null, [401, 200, 403, 200]],
    ['POST /content-pages', 'POST', '/api/v1/content-pages', ['title' => ['en' => 'AZ New', 'ar' => 'جديد']], [401, 403, 403, 201]],
    ['PUT /content-pages/{id}', 'PUT', '/api/v1/content-pages/' . $cpId, ['is_active' => 1], [401, 403, 403, 200]],
    ['PATCH page toggle', 'PATCH', '/api/v1/content-pages/' . $cpId . '/toggle-active', null, [401, 403, 403, 200]],
    ['POST attach-sections', 'POST', '/api/v1/content-pages/' . $cpId . '/attach-sections', ['sections' => []], [401, 403, 403, 200]],
    ['DELETE /content-pages/{id}', 'DELETE', '/api/v1/content-pages/' . $azCp, null, [401, 403, 403, 200]],
    ['GET /sections', 'GET', '/api/v1/sections', null, [401, 200, 403, 200]],
    ['GET /sections/{id}', 'GET', '/api/v1/sections/' . $secId, null, [401, 200, 403, 200]],
    ['POST /sections', 'POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'AZ Sec', 'ar' => 'قسم'], 'is_active' => 1], [401, 403, 403, 200]],
    ['PUT /sections/{id}', 'PUT', '/api/v1/sections/' . $secId, ['title_visible' => 1], [401, 403, 403, 200]],
    ['PATCH sec toggle', 'PATCH', '/api/v1/sections/' . $secId . '/toggle-active', null, [401, 403, 403, 200]],
    ['POST /sections/reorder', 'POST', '/api/v1/sections/reorder', ['sections' => $reorderIds], [401, 403, 403, 200]],
    ['DELETE /sections/{id}', 'DELETE', '/api/v1/sections/' . $azSec, null, [401, 403, 403, 200]],
    ['GET /section-types', 'GET', '/api/v1/section-types', null, [401, 200, 403, 200]],
    ['GET /section-types/{type}', 'GET', '/api/v1/section-types/banners', null, [401, 200, 403, 200]],
    ['GET settings', 'GET', '/api/v1/section-types/banners/settings', null, [401, 200, 403, 200]],
    ['POST /section-types', 'POST', '/api/v1/section-types', ['type' => 'az-new'], [401, 403, 403, 200]],
    ['POST settings', 'POST', '/api/v1/section-types/banners/settings', ['front' => [], 'back' => []], [401, 403, 403, 200]],
    ['PUT /section-types/{type}', 'PUT', '/api/v1/section-types/az-del', ['type' => 'az-del'], [401, 403, 403, 200]],
    ['DELETE /section-types/{type}', 'DELETE', '/api/v1/section-types/az-del', null, [401, 403, 403, 200]],
];

$allOk = true;
foreach ($matrix as [$label, $method, $uri, $payload, $expect]) {
    [$g] = http($method, $uri, $payload);
    [$v] = http($method, $uri, $payload, $GLOBALS['viewToken']);
    [$p] = http($method, $uri, $payload, $GLOBALS['plainToken']);
    [$a] = http($method, $uri, $payload, $GLOBALS['adminToken']);
    $ok = $g === $expect[0] && $v === $expect[1] && $p === $expect[2] && $a === $expect[3];
    if (!$ok) {
        $allOk = false;
    }
    ev('  ' . $label . ': guest=' . $g . ' viewer=' . $v . ' plain=' . $p . ' admin=' . $a . ' -> ' . ($ok ? 'PASS' : 'FAIL (expected ' . implode('/', $expect) . ')'));
    record('TC-AZ-' . substr(md5($uri . $method), 0, 8), $ok, $label);
}
record('TC-AZ-ALL', $allOk, 'full 4-class authorization matrix');

// unauthorized writes -> zero DB mutation (already proven per-row above via status; re-prove snapshot)
$snapBefore = snapJson($GLOBALS['tables']);
http('POST', '/api/v1/content-pages', ['title' => ['en' => 'X', 'ar' => 'ص']], $GLOBALS['viewToken']);
http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'X', 'ar' => 'ص']], $GLOBALS['plainToken']);
http('PATCH', '/api/v1/sections/' . $secId . '/toggle-active', [], $GLOBALS['viewToken']);
http('POST', '/api/v1/section-types', ['type' => 'x'], $GLOBALS['plainToken']);
record('TC-AZ-ZERO', snapJson($GLOBALS['tables']) === $snapBefore, 'unauthorized writes cause ZERO DB mutation');

// ---- security extras ----
// mass-assignment on content-page create (slug + is_active overrides ignored)
[$c, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Mass Page', 'ar' => 'كتلة'], 'slug' => 'evil-slug', 'is_active' => 0, 'id' => 555], $GLOBALS['adminToken']);
$mp = row('content_pages', $j['data']['id']);
ev('  mass-attempt page row: ' . json_encode($mp));
record('TC-SEC-MASS', $c === 201 && $mp->slug === 'mass-page' && (int) $mp->is_active === 1 && (int) $mp->id !== 555, 'content-page mass assignment blocked (slug auto, is_active forced, id ignored)');

// nonexistent ids
[$c] = http('GET', '/api/v1/content-pages/999999', null, $GLOBALS['adminToken']);
[$c2] = http('DELETE', '/api/v1/sections/999999', null, $GLOBALS['adminToken']);
[$c3] = http('PATCH', '/api/v1/content-pages/999999/toggle-active', null, $GLOBALS['adminToken']);
record('TC-SEC-404', $c === 404 && $c2 === 404 && $c3 === 404, 'invalid ids -> 404 (' . $c . '/' . $c2 . '/' . $c3 . ')');

// inactive section hidden from public page
[$c, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Vis Page', 'ar' => 'رؤية']], $GLOBALS['adminToken']);
$visPage = $j['data']['id'];
[$c, $j] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Vis Hidden', 'ar' => 'مخفي'], 'is_active' => 0], $GLOBALS['adminToken']);
$visSec = $j['data']['id'];
http('POST', '/api/v1/content-pages/' . $visPage . '/attach-sections', ['sections' => [$visSec]], $GLOBALS['adminToken']);
[$c, $j] = http('GET', '/api/v1/general/content-pages/vis-page');
record('TC-SEC-INACTIVE-SEC', $c === 200 && count($j['data']['sections'] ?? []) === 0, 'inactive section hidden from public page');