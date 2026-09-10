<?php

// =====================================================================
// PHASE 3 — CONTENT PAGE CREATE MATRIX
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 3 — CONTENT PAGE CREATE MATRIX');

$cpBefore = DB::table('content_pages')->count();

// --- valid en+ar
[$code, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Electronics', 'ar' => 'إلكترونيات']], $GLOBALS['adminToken']);
$id = $j['data']['id'] ?? null;
$r = row('content_pages', (int) $id);
ev('  TC-CP-001 HTTP=' . $code . ' id=' . var_export($id, true));
ev('  TC-CP-001 DB row: ' . json_encode($r));
$t = json($r->title);
$ok = $code === 201 && $r !== null
    && $t['en'] === 'Electronics' && $t['ar'] === 'إلكترونيات'
    && $r->slug === 'electronics' && (int) $r->is_active === 1
    && $r->created_at !== null && $r->updated_at !== null;
// response <-> DB
$ok = $ok && (int) $j['data']['id'] === (int) $r->id && $j['data']['slug'] === $r->slug
    && $j['data']['is_active'] === true && $j['data']['title'] === 'Electronics';
record('TC-CP-001', $ok, '201; DB row + response both verified (id/slug/title/is_active/timestamps)');

// --- english only
[$code, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Audio']], $GLOBALS['adminToken']);
$id2 = $j['data']['id'] ?? null;
$r2 = row('content_pages', (int) $id2);
$ok2 = $code === 201 && $r2 !== null && json($r2->title) === ['en' => 'Audio'] && $r2->slug === 'audio';
record('TC-CP-002', $ok2, 'english-only create: DB title=' . json_encode(json($r2?->title)) . ' slug=' . $r2?->slug);

// --- arabic only: title.ar supplied but title.en MISSING -> architecture requires en -> 422 zero mutation
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['ar' => 'عربي فقط']], $GLOBALS['adminToken']);
$zero = snapJson($GLOBALS['tables']) === $snapBefore;
record('TC-CP-003', $code === 422 && $zero, 'arabic-only (no title.en) -> HTTP=' . $code . ' zeroMutation=' . ($zero ? 'yes' : 'no'));

// --- missing title
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', [], $GLOBALS['adminToken']);
record('TC-CP-004', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'missing title -> HTTP=' . $code . ' zero mutation');

// --- missing title.en
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['fr' => 'Bonjour']], $GLOBALS['adminToken']);
record('TC-CP-005', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'missing title.en -> HTTP=' . $code . ' zero mutation');

// --- invalid title type
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', ['title' => 'plain-string'], $GLOBALS['adminToken']);
record('TC-CP-006', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'invalid title type -> HTTP=' . $code . ' zero mutation');

// --- oversized title
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['en' => str_repeat('x', 31)]], $GLOBALS['adminToken']);
record('TC-CP-007', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'oversized title (31 chars) -> HTTP=' . $code . ' zero mutation');

// --- duplicate translated title (en already exists)
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Electronics', 'ar' => 'مكرر']], $GLOBALS['adminToken']);
record('TC-CP-008', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'duplicate title.en -> HTTP=' . $code . ' zero mutation (unique-translation guard)');

// --- unauthorized (viewer) + guest
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Blocked', 'ar' => 'محظور']], $GLOBALS['viewToken']);
record('TC-CP-009', $code === 403 && snapJson($GLOBALS['tables']) === $snapBefore, 'viewer create -> HTTP=' . $code . ' zero mutation');

$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Guest', 'ar' => 'زائر']]);
record('TC-CP-010', $code === 401 && snapJson($GLOBALS['tables']) === $snapBefore, 'guest create -> HTTP=' . $code . ' zero mutation');

ev('  AFTER content_pages=' . DB::table('content_pages')->count() . ' (expected +2)');
record('TC-CP-COUNT', DB::table('content_pages')->count() === $cpBefore + 2, 'create count matches: only 2 rows added');

// =====================================================================
// PHASE 4 — CONTENT PAGE UPDATE (per-field) + response<->DB
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 4 — CONTENT PAGE UPDATE (per-field)');

$targetId = $id;
$before = row('content_pages', $targetId);
ev('  BEFORE row: ' . json_encode($before));

// 1) title.en only
[$code, $j] = http('PUT', '/api/v1/content-pages/' . $targetId, ['title' => ['en' => 'Smart Electronics']], $GLOBALS['adminToken']);
$after = row('content_pages', $targetId);
$t = json($after->title);
$ok = $code === 200
    && $t['en'] === 'Smart Electronics' && $t['ar'] === 'إلكترونيات'          // en changed, ar preserved
    && (int) $after->is_active === (int) $before->is_active                    // is_active unchanged
    && $after->slug === $before->slug && (string) $after->created_at === (string) $before->created_at
    && $after->updated_at !== null;
$ok = $ok && $j['data']['title'] === 'Smart Electronics' && (int) $j['data']['id'] === $targetId && $j['data']['slug'] === $after->slug;
record('TC-CP-U-001', $ok, 'title.en update; DB + response verified; slug/is_active/created_at unchanged');

// 2) title.ar only
[$code, $j] = http('PUT', '/api/v1/content-pages/' . $targetId, ['title' => ['ar' => 'الإلكترونيات الذكية']], $GLOBALS['adminToken']);
$after2 = row('content_pages', $targetId);
$t2 = json($after2->title);
$ok = $code === 200 && $t2['en'] === 'Smart Electronics' && $t2['ar'] === 'الإلكترونيات الذكية'
    && (int) $after2->is_active === (int) $before->is_active;
record('TC-CP-U-002', $ok, 'title.ar update; en preserved, ar changed, is_active unchanged');

// 3) is_active only
[$code, $j] = http('PUT', '/api/v1/content-pages/' . $targetId, ['is_active' => 0], $GLOBALS['adminToken']);
$after3 = row('content_pages', $targetId);
$ok = $code === 200 && (int) $after3->is_active === 0
    && json($after3->title) === ['en' => 'Smart Electronics', 'ar' => 'الإلكترونيات الذكية']   // title unchanged
    && $after3->slug === $before->slug;
record('TC-CP-U-003', $ok, 'is_active update; title/slug unchanged, DB is_active=0');

// restore active for later phases
http('PUT', '/api/v1/content-pages/' . $targetId, ['is_active' => 1], $GLOBALS['adminToken']);

// invalid updates -> ZERO mutation
$invalidUpd = [
    'is_active=5' => ['is_active' => 5],
    'title too long' => ['title' => ['en' => str_repeat('y', 31)]],
    'duplicate title' => ['title' => ['en' => 'Audio']], // Audio is another existing page title
    'title not array' => ['title' => 'nope'],
];
foreach ($invalidUpd as $label => $payload) {
    $snapBefore = snapJson($GLOBALS['tables']);
    [$code] = http('PUT', '/api/v1/content-pages/' . $targetId, $payload, $GLOBALS['adminToken']);
    $zero = snapJson($GLOBALS['tables']) === $snapBefore;
    record('TC-CP-U-INV-' . md5($label), $code === 422 && $zero, 'invalid update (' . $label . ') -> HTTP=' . $code . ' zeroMutation=' . ($zero ? 'yes' : 'no'));
}

// =====================================================================
// PHASE 5 — CONTENT PAGE DELETE + ORPHAN SECTIONS + PUBLIC CONTRACT
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 5 — CONTENT PAGE DELETE / PUBLIC CONTRACT');

// create a page + 2 sections attached
[$code, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'To Delete', 'ar' => 'للحذف']], $GLOBALS['adminToken']);
$delPage = $j['data']['id'];
$secIds = [];
foreach ([['en' => 'Orphan One', 'ar' => 'يتيم'], ['en' => 'Orphan Two', 'ar' => 'يتيمان']] as $t) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => $t, 'is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);
    $secIds[] = $sj['data']['id'];
}
http('POST', '/api/v1/content-pages/' . $delPage . '/attach-sections', ['sections' => $secIds], $GLOBALS['adminToken']);
ev('  attached sections ' . json_encode($secIds) . ' -> content_page_id=' . json_encode(DB::table('sections')->whereIn('id', $secIds)->pluck('content_page_id')));

[$code] = http('DELETE', '/api/v1/content-pages/' . $delPage, [], $GLOBALS['adminToken']);
$gone = row('content_pages', $delPage) === null;
$orphans = DB::table('sections')->whereIn('id', $secIds)->get(['id', 'content_page_id']);
ev('  DELETE HTTP=' . $code . ' pageGone=' . ($gone ? 'yes' : 'no'));
ev('  orphan sections after delete: ' . json_encode($orphans));
$allNull = DB::table('sections')->whereIn('id', $secIds)->whereNotNull('content_page_id')->count() === 0;
record('TC-CP-D-001', $code === 200 && $gone, 'hard delete: SELECT returns no row');
record('TC-CP-D-002', $allNull && DB::table('sections')->whereIn('id', $secIds)->count() === 2, 'sections survive with content_page_id=NULL (nullOnDelete contract)');

// --- public contract: active vs inactive
http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Hidden Page', 'ar' => 'صفحة مخفية']], $GLOBALS['adminToken']);
$hiddenId = (int) DB::table('content_pages')->where('slug', 'hidden-page')->value('id');
http('PATCH', '/api/v1/content-pages/' . $hiddenId . '/toggle-active', [], $GLOBALS['adminToken']);
$dbInactive = (int) DB::table('content_pages')->where('id', $hiddenId)->value('is_active');

[$code, $j] = http('GET', '/api/v1/general/content-pages');
$slugs = array_column($j['data'] ?? [], 'slug');
$activeDbIds = DB::table('content_pages')->where('is_active', 1)->pluck('id', 'slug')->all();
ev('  public index slugs: ' . json_encode($slugs));
$crossCheck = collect($slugs)->every(fn ($s) => isset($activeDbIds[$s])) && collect($slugs)->count() === count($activeDbIds);
record('TC-PB-001', $code === 200 && in_array('electronics', $slugs, true) && !in_array('hidden-page', $slugs, true) && $crossCheck, 'active-only + every returned slug cross-checked against DB is_active=1 (inactive hidden)');

[$code, $j] = http('GET', '/api/v1/general/content-pages/electronics');
$dbRow = DB::table('content_pages')->where('slug', 'electronics')->first();
$crossShow = $code === 200 && (int) $j['data']['id'] === (int) $dbRow->id && $j['data']['slug'] === $dbRow->slug;
record('TC-PB-002', $crossShow, 'active show 200; response id/slug cross-checked against DB (inactive=' . $dbInactive . ')');

[$code] = http('GET', '/api/v1/general/content-pages/hidden-page');
record('TC-PB-003', $code === 404, 'inactive show -> HTTP=' . $code . ' (404)');