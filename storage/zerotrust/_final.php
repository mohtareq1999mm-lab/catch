<?php

// =====================================================================
// PHASE 17 — GLOBAL INTEGRITY + FULL-DB ACCOUNTING + QUEUE + SUMMARY
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 17 — GLOBAL INTEGRITY');

$dupSlugs = DB::table('content_pages')->selectRaw('slug, count(*) c')->groupBy('slug')->havingRaw('count(*) > 1')->count();
record('TC-IN-001', $dupSlugs === 0, 'no duplicate content_page slugs (dups=' . $dupSlugs . ')');

$orphanSecs = DB::table('sections as s')->leftJoin('content_pages as p', 'p.id', '=', 's.content_page_id')
    ->whereNotNull('s.content_page_id')->whereNull('p.id')->count();
record('TC-IN-002', $orphanSecs === 0, 'no sections pointing at missing pages (orphans=' . $orphanSecs . ')');

$badBool = DB::table('content_pages')->whereNotIn('is_active', [0, 1])->count()
    + DB::table('sections')->whereNotIn('is_active', [0, 1])->count()
    + DB::table('sections')->whereNotIn('title_visible', [0, 1])->count();
record('TC-IN-003', $badBool === 0, 'no invalid booleans (is_active/title_visible)');

$orphanSettings = DB::table('section_type_settings as sts')->leftJoin('section_types as st', 'st.id', '=', 'sts.section_type_id')
    ->whereNull('st.id')->count();
record('TC-IN-004', $orphanSettings === 0, 'no orphan section_type_settings (cascade works)');

$nullTitles = DB::table('content_pages')->whereNull('title')->count()
    + DB::table('sections')->whereNull('title')->count();
record('TC-IN-005', $nullTitles === 0, 'no null titles');

$badJson = 0;
foreach (DB::table('content_pages')->pluck('title') as $t) {
    if (json($t) === null) {
        $badJson++;
    }
}
foreach (DB::table('sections')->pluck('title') as $t) {
    if (json($t) === null) {
        $badJson++;
    }
}
record('TC-IN-006', $badJson === 0, 'all titles valid JSON');

$badSetting = 0;
foreach (DB::table('sections')->pluck('setting') as $s) {
    if ($s !== null && json($s) === null) {
        $badSetting++;
    }
}
record('TC-IN-007', $badSetting === 0, 'all non-null section settings valid JSON');

$cpCount = DB::table('content_pages')->count();
$secCount = DB::table('sections')->count();
record('TC-IN-008', $cpCount === $GLOBALS['ztStart']['content_pages'] + 8 && $secCount >= $GLOBALS['ztStart']['sections'] + 31, 'final counts sane: pages=' . $cpCount . ' sections=' . $secCount);

// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 17b — FULL-DB ACCOUNTING');

$end = snap($GLOBALS['tables']);
$delta = [];
foreach ($end as $t => $c) {
    $d = $c - $GLOBALS['ztStart'][$t];
    if ($d !== 0) {
        $delta[$t] = $d;
    }
}
ksort($delta);
$allowed = [
    'content_pages', 'sections', 'section_types', 'section_type_settings',
    'banners', 'sliders', 'promotions', 'tags', 'categories', 'products',
    'flash_sales', 'brands', 'coupons',
    'users', 'roles', 'role_has_permissions', 'model_has_roles', 'personal_access_tokens',
    'telescope_entries', 'telescope_entries_tags', 'telescope_monitoring', 'settings',
];
$unexpected = array_diff_key($delta, array_flip($allowed));
record('TC-AC-001', count($unexpected) === 0, 'no DB writes outside module/entity/auth tables (unexpected: ' . json_encode($unexpected) . ')');

$expected = [
    'content_pages' => 8, 'sections' => 31, 'section_types' => 3,
    'banners' => 2, 'sliders' => 1, 'promotions' => 1, 'tags' => 2,
    'categories' => 1, 'products' => 2, 'flash_sales' => 1, 'brands' => 1, 'coupons' => 1,
    'users' => 3, 'roles' => 2, 'role_has_permissions' => 3, 'model_has_roles' => 3,
    'personal_access_tokens' => 3,
];
$mismatch = [];
foreach ($expected as $t => $exp) {
    $act = $delta[$t] ?? 0;
    if ($act !== $exp) {
        $mismatch[$t] = ['expected' => $exp, 'actual' => $act];
    }
}
ev('  deltas: ' . json_encode($delta));
record('TC-AC-002', count($mismatch) === 0, 'exact per-table deltas match expectations (mismatch: ' . json_encode($mismatch) . ')');
record('TC-AC-003', (int) ($delta['flash_sale_products'] ?? 0) === 0, 'flash_sale_products untouched (no accidental pivot writes)');

// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 17c — QUEUE SANITY');

$q = app('queue');
record('TC-Q-001', config('queue.default') === 'redis', 'default queue connection is redis (' . config('queue.default') . ')');
record('TC-Q-002', get_class($q->connection()) === Illuminate\Queue\RedisQueue::class, 'resolved connection = ' . get_class($q->connection()));
record('TC-Q-003', DB::table('jobs')->count() === 0 && DB::table('failed_jobs')->count() === 0, 'zero orphan rows in jobs/failed_jobs tables');

// =====================================================================
ev('');
ev('=================================================================');
ev('ZEROTRUST SUMMARY');

$results = $GLOBALS['ztResults'];
$pass = count(array_filter($results, fn ($r) => $r['ok']));
$fail = count($results) - $pass;
foreach ($results as $r) {
    if (!$r['ok']) {
        ev('  FAIL ' . $r['id'] . ' -> ' . $r['detail']);
    }
}
ev('PASS=' . $pass . ' FAIL=' . $fail . ' TOTAL=' . count($results));
exit($fail === 0 ? 0 : 1);