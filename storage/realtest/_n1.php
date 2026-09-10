<?php

// =====================================================================
// PHASE 21 — N+1 / QUERY COUNT VERIFICATION
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 21 — N+1 / QUERY COUNT VERIFICATION');

function countQueriesFor(string $uri, string $method = 'GET', ?array $payload = null, ?string $token = null): array
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    [$code] = http($method, $uri, $payload, $token);
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    $cat = ['content_pages' => 0, 'sections' => 0, 'section_types' => 0, 'section_type_settings' => 0];
    foreach ($log as $q) {
        $sql = $q['query'];
        foreach ($cat as $t => $v) {
            if (str_contains($sql, $t)) {
                $cat[$t]++;
            }
        }
    }
    return ['status' => $code, 'total' => count($log), 'cat' => $cat];
}

// public page with 10 sections (strong N+1 check)
$publicN = countQueriesFor('/api/v1/general/content-pages/public-storefront');
$sectionCount = DB::table('sections')->where('content_page_id', DB::table('content_pages')->where('slug', 'public-storefront')->value('id'))->count();
ev('  PUBLIC page show (sections=' . $sectionCount . '): status=' . $publicN['status'] . ' totalQueries=' . $publicN['total'] . ' cat=' . json_encode($publicN['cat']));
record('TC-N1-001', $publicN['status'] === 200 && $publicN['cat']['content_pages'] === 1 && $publicN['cat']['sections'] === 1
    && $publicN['cat']['section_types'] === 1 && $publicN['cat']['section_type_settings'] === 1,
    'eager loaded: 1 query per table regardless of ' . $sectionCount . ' sections (no per-section queries)');

// public index
$pubIndexN = countQueriesFor('/api/v1/general/content-pages');
$pageCount = DB::table('content_pages')->count();
ev('  PUBLIC index (pages=' . $pageCount . '): status=' . $pubIndexN['status'] . ' totalQueries=' . $pubIndexN['total'] . ' cat=' . json_encode($pubIndexN['cat']));
record('TC-N1-002', $pubIndexN['status'] === 200 && $pubIndexN['cat']['section_types'] === 1 && $pubIndexN['cat']['section_type_settings'] === 1,
    'index eager loads sections once');

// admin sections index
$adminSecN = countQueriesFor('/api/v1/sections', 'GET', null, $adminToken);
$allSections = DB::table('sections')->count();
ev('  ADMIN sections index (sections=' . $allSections . '): status=' . $adminSecN['status'] . ' totalQueries=' . $adminSecN['total'] . ' cat=' . json_encode($adminSecN['cat']));
record('TC-N1-003', $adminSecN['status'] === 200 && $adminSecN['cat']['sections'] === 1 && $adminSecN['cat']['section_types'] === 1 && $adminSecN['cat']['section_type_settings'] === 1,
    'admin sections index eager loads once');

// admin content page show
$showN = countQueriesFor('/api/v1/content-pages/' . DB::table('content_pages')->where('slug', 'public-storefront')->value('id'), 'GET', null, $adminToken);
ev('  ADMIN content page show: status=' . $showN['status'] . ' totalQueries=' . $showN['total'] . ' cat=' . json_encode($showN['cat']));
record('TC-N1-004', $showN['status'] === 200 && $showN['cat']['sections'] === 1 && $showN['cat']['section_types'] === 1 && $showN['cat']['section_type_settings'] === 1,
    'admin show eager loads once');