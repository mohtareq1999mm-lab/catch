<?php

// =====================================================================
// PHASE 23 — VALIDATION + ZERO DB MUTATION
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 23 — VALIDATION + ZERO DB MUTATION');

$cpCountId = (int) DB::table('content_pages')->orderByDesc('id')->value('id');

$invalid = [
    'content page missing title' => ['POST', '/api/v1/content-pages', []],
    'content page missing title.en' => ['POST', '/api/v1/content-pages', ['title' => ['ar' => 'عربي']]],
    'content page title not array' => ['POST', '/api/v1/content-pages', ['title' => 'plain']],
    'content page title too long' => ['POST', '/api/v1/content-pages', ['title' => ['en' => str_repeat('x', 31)]]],
    'content page update bad is_active' => ['PUT', '/api/v1/content-pages/' . $cpCountId, ['is_active' => 5]],
    'section missing type' => ['POST', '/api/v1/sections', ['title' => ['en' => 'No Type', 'ar' => 'بدون نوع']]],
    'section invalid type' => ['POST', '/api/v1/sections', ['type' => 'not-a-type', 'title' => ['en' => 'Bad', 'ar' => 'سيئ']]],
    'section missing title' => ['POST', '/api/v1/sections', ['type' => 'banners']],
    'section bad is_active' => ['POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'X', 'ar' => 'إكس'], 'is_active' => 9]],
    'section bad title_visible' => ['POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'X', 'ar' => 'إكس'], 'title_visible' => 'no']],
    'attach invalid section ids' => ['POST', '/api/v1/content-pages/' . $cpCountId . '/attach-sections', ['sections' => [999999]]],
    'reorder empty' => ['POST', '/api/v1/sections/reorder', ['sections' => []]],
    'reorder duplicate' => ['POST', '/api/v1/sections/reorder', ['sections' => [$cpCountId, $cpCountId]]],
    'reorder non-integer' => ['POST', '/api/v1/sections/reorder', ['sections' => ['abc']]],
    'reorder non-existent' => ['POST', '/api/v1/sections/reorder', ['sections' => [999999]]],
    'section type empty' => ['POST', '/api/v1/section-types', ['type' => '']],
    'section type duplicate' => ['POST', '/api/v1/section-types', ['type' => 'banners']],
    'settings not array' => ['POST', '/api/v1/section-types/banners/settings', ['front' => 'string-not-array']],
];

$tables = ['content_pages', 'sections', 'section_types', 'section_type_settings'];
$allOk = true;
foreach ($invalid as $label => [$method, $uri, $payload]) {
    $before = snap($tables);
    [$code] = http($method, $uri, $payload, $adminToken);
    $after = snap($tables);
    $zeroMut = $before === $after;
    if ($code !== 422 || !$zeroMut) {
        $allOk = false;
    }
    ev('  invalid (' . $label . '): HTTP=' . $code . ' zeroMutation=' . ($zeroMut ? 'yes' : 'NO') . ' -> ' . (($code === 422 && $zeroMut) ? 'PASS' : 'FAIL'));
    record('TC-VA-' . substr(md5($label), 0, 8), $code === 422 && $zeroMut, $label . ' HTTP=' . $code);
}
record('TC-VA-ALL', $allOk, '18 invalid requests -> 422 with ZERO unintended DB mutation');