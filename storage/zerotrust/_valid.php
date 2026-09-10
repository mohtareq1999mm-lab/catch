<?php

// =====================================================================
// PHASE 15 — VALIDATION ZERO-MUTATION MATRIX
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 15 — VALIDATION ZERO-MUTATION');

$target = (int) DB::table('content_pages')->orderByDesc('id')->value('id');
$sec = (int) DB::table('sections')->orderByDesc('id')->value('id');

$invalid = [
    'cp missing title' => ['POST', '/api/v1/content-pages', []],
    'cp missing title.en' => ['POST', '/api/v1/content-pages', ['title' => ['ar' => 'ع']]],
    'cp title not array' => ['POST', '/api/v1/content-pages', ['title' => 'x']],
    'cp title too long' => ['POST', '/api/v1/content-pages', ['title' => ['en' => str_repeat('a', 31)]]],
    'cp update bad is_active' => ['PUT', '/api/v1/content-pages/' . $target, ['is_active' => 7]],
    'cp create duplicate title' => ['POST', '/api/v1/content-pages', ['title' => ['en' => 'Audio', 'ar' => 'ز']]],
    'sec missing type' => ['POST', '/api/v1/sections', ['title' => ['en' => 'T', 'ar' => 'ت']]],
    'sec bad type' => ['POST', '/api/v1/sections', ['type' => 'nope', 'title' => ['en' => 'T', 'ar' => 'ت']]],
    'sec missing title' => ['POST', '/api/v1/sections', ['type' => 'banners']],
    'sec bad is_active' => ['POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'T', 'ar' => 'ت'], 'is_active' => 2]],
    'sec bad title_visible' => ['POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'T', 'ar' => 'ت'], 'title_visible' => 'y']],
    'sec title too long' => ['POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => str_repeat('b', 51), 'ar' => 'ت']]],
    'sec bad order' => ['POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'T', 'ar' => 'ت'], 'order' => 'x']],
    'sec update bad order' => ['PUT', '/api/v1/sections/' . $sec, ['order' => 'x']],
    'attach bad ids' => ['POST', '/api/v1/content-pages/' . $target . '/attach-sections', ['sections' => ['a']]],
    'attach nonexistent' => ['POST', '/api/v1/content-pages/' . $target . '/attach-sections', ['sections' => [999999]]],
    'reorder empty' => ['POST', '/api/v1/sections/reorder', ['sections' => []]],
    'reorder duplicate' => ['POST', '/api/v1/sections/reorder', ['sections' => [$sec, $sec]]],
    'reorder nonexistent' => ['POST', '/api/v1/sections/reorder', ['sections' => [999999]]],
    'reorder string' => ['POST', '/api/v1/sections/reorder', ['sections' => ['x']]],
    'reorder float' => ['POST', '/api/v1/sections/reorder', ['sections' => [1.5]]],
    'reorder null' => ['POST', '/api/v1/sections/reorder', ['sections' => [null]]],
    'reorder missing' => ['POST', '/api/v1/sections/reorder', []],
    'reorder wrong type' => ['POST', '/api/v1/sections/reorder', ['sections' => 'x']],
    'type empty' => ['POST', '/api/v1/section-types', ['type' => '']],
    'type duplicate' => ['POST', '/api/v1/section-types', ['type' => 'banners']],
    'type too long' => ['POST', '/api/v1/section-types', ['type' => str_repeat('c', 101)]],
    'settings not array' => ['POST', '/api/v1/section-types/banners/settings', ['front' => 'x']],
];

$allOk = true;
foreach ($invalid as $label => [$method, $uri, $payload]) {
    $before = snapJson($GLOBALS['tables']);
    [$code] = http($method, $uri, $payload, $GLOBALS['adminToken']);
    $zero = snapJson($GLOBALS['tables']) === $before;
    $ok = $code === 422 && $zero;
    if (!$ok) {
        $allOk = false;
    }
    ev('  ' . str_pad($label, 30) . ' HTTP=' . $code . ' zeroMutation=' . ($zero ? 'yes' : 'NO') . ' -> ' . ($ok ? 'PASS' : 'FAIL'));
    record('TC-VA-' . substr(md5($label), 0, 8), $ok, $label . ' HTTP=' . $code);
}
record('TC-VA-ALL', $allOk, count($invalid) . ' invalid requests -> 422 with ZERO unintended DB mutation (byte-identical snapshots)');

// contract: empty/missing sections on attach = detach-all (documented behavior, not a validation error)
[$c, $j] = http('POST', '/api/v1/content-pages/' . $target . '/attach-sections', [], $GLOBALS['adminToken']);
$detachedCount = DB::table('sections')->where('content_page_id', $target)->count();
record('TC-VA-DETACH-ALL', $c === 200 && $detachedCount === 0, 'empty attach = detach-all contract (HTTP=' . $c . ', remaining=' . $detachedCount . ')');