<?php

// =====================================================================
// PHASE 22 — AUTHORIZATION MATRIX (guest / viewer / admin)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 22 — AUTHORIZATION MATRIX');

$cpId = (int) DB::table('content_pages')->where('slug', 'home-electronics')->value('id');
$secId = (int) DB::table('sections')->orderBy('id')->value('id');
$reorderIds = DB::table('sections')->orderBy('id')->pluck('id')->take(2)->all();

// freshly-created targets so destructive rows don't break later reads
[$c, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'AZ Temp Page', 'ar' => 'صفحة مؤقتة']], $adminToken);
$azCpId = $j['data']['id'];
[$c, $j] = http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'AZ Temp Section', 'ar' => 'قسم مؤقت'], 'is_active' => 1], $adminToken);
$azSecId = $j['data']['id'];
[$c, $j] = http('POST', '/api/v1/section-types', ['type' => 'az-type'], $adminToken);
ev('  AZ targets: cp=' . $azCpId . ' section=' . $azSecId);

$readUris = [
    '/api/v1/content-pages', '/api/v1/content-pages/' . $cpId,
    '/api/v1/sections', '/api/v1/sections/' . $secId,
    '/api/v1/section-types', '/api/v1/section-types/banners', '/api/v1/section-types/banners/settings',
];

$matrix = [
    ['GET /content-pages', 'GET', '/api/v1/content-pages', null, 200],
    ['GET /content-pages/{id}', 'GET', '/api/v1/content-pages/' . $cpId, null, 200],
    ['POST /content-pages', 'POST', '/api/v1/content-pages', ['title' => ['en' => 'AZ Temp 2', 'ar' => 'مؤقت']], 201],
    ['PUT /content-pages/{id}', 'PUT', '/api/v1/content-pages/' . $cpId, ['is_active' => 1], 200],
    ['PATCH toggle-active', 'PATCH', '/api/v1/content-pages/' . $cpId . '/toggle-active', null, 200],
    ['POST attach-sections', 'POST', '/api/v1/content-pages/' . $cpId . '/attach-sections', ['sections' => []], 200],
    ['DELETE /content-pages/{id}', 'DELETE', '/api/v1/content-pages/' . $azCpId, null, 200],
    ['GET /sections', 'GET', '/api/v1/sections', null, 200],
    ['POST /sections', 'POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'AZ Section 2', 'ar' => 'قسم'], 'is_active' => 1], 200],
    ['GET /sections/{id}', 'GET', '/api/v1/sections/' . $secId, null, 200],
    ['PUT /sections/{id}', 'PUT', '/api/v1/sections/' . $secId, ['title_visible' => 1], 200],
    ['PATCH toggle-active', 'PATCH', '/api/v1/sections/' . $secId . '/toggle-active', null, 200],
    ['POST /sections/reorder', 'POST', '/api/v1/sections/reorder', ['sections' => $reorderIds], 200],
    ['DELETE /sections/{id}', 'DELETE', '/api/v1/sections/' . $azSecId, null, 200],
    ['GET /section-types', 'GET', '/api/v1/section-types', null, 200],
    ['POST /section-types', 'POST', '/api/v1/section-types', ['type' => 'az-type-2'], 200],
    ['GET /section-types/{type}', 'GET', '/api/v1/section-types/banners', null, 200],
    ['GET settings', 'GET', '/api/v1/section-types/banners/settings', null, 200],
    ['POST settings', 'POST', '/api/v1/section-types/banners/settings', ['front' => [], 'back' => []], 200],
    ['PUT /section-types/{type}', 'PUT', '/api/v1/section-types/az-type', ['type' => 'az-type'], 200],
    ['DELETE /section-types/{type}', 'DELETE', '/api/v1/section-types/az-type', null, 200],
];

foreach ($matrix as [$label, $method, $uri, $payload, $adminOk]) {
    [$g] = http($method, $uri, $payload);
    [$v] = http($method, $uri, $payload, $viewToken);
    [$a] = http($method, $uri, $payload, $adminToken);
    $isRead = $method === 'GET';
    $guestOk = $g === 401;
    $viewerOk = $isRead ? ($v === 200) : ($v === 403);
    $adminOkReal = $a === $adminOk;
    $ok = $guestOk && $viewerOk && $adminOkReal;
    ev('  ' . $label . ' [' . $uri . ']: guest=' . $g . ' viewer=' . $v . ' admin=' . $a . ' -> ' . ($ok ? 'PASS' : 'FAIL'));
    record('TC-AZ-' . substr(md5($uri . $method), 0, 8), $ok, $label . ' guest=' . $g . ' viewer=' . $v . ' admin=' . $a);
}

// plain user (authenticated, no page permissions) on a read route -> 403
[$g] = http('GET', '/api/v1/sections');
[$p] = http('GET', '/api/v1/sections', null, $plainToken);
ev('  plain user on GET /sections: guest=' . $g . ' plain=' . $p);
record('TC-AZ-PLAIN', $g === 401 && $p === 403, 'unauthenticated 401 / no-permission 403');