<?php

// =====================================================================
// PHASE 3 — CONTENT PAGE CREATE MATRIX
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 3 — CONTENT PAGE CREATE MATRIX');

$cpBefore = DB::table('content_pages')->count();
ev('  BEFORE content_pages=' . $cpBefore);

// TC-CP-001 valid active page EN+AR
[$code, $json] = http('POST', '/api/v1/content-pages', [
    'title' => ['en' => 'Home Electronics', 'ar' => 'الرئيسية إلكترونيات'],
], $adminToken);
$cp001 = $json['data']['id'] ?? null;
ev('  TC-CP-001 HTTP=' . $code . ' returned id=' . var_export($cp001, true));
$cpRow = row('content_pages', (int) $cp001);
ev('  TC-CP-001 DB row: ' . json_encode($cpRow));
$t = json_decode($cpRow->title, true);
record('TC-CP-001', $code === 201 && $cpRow !== null && (int) $cpRow->is_active === 1
    && $t['en'] === 'Home Electronics' && $t['ar'] === 'الرئيسية إلكترونيات'
    && $cpRow->slug === 'home-electronics', 'active page, slug=home-electronics, JSON title stored');

// TC-CP-002
[$code, $json] = http('POST', '/api/v1/content-pages', [
    'title' => ['en' => 'Mobile Phones', 'ar' => 'الهواتف المحمولة'],
], $adminToken);
$cp002 = $json['data']['id'] ?? null;
record('TC-CP-002', $code === 201 && row('content_pages', (int) $cp002) !== null, 'HTTP=' . $code . ' id=' . $cp002);

// TC-CP-003
[$code, $json] = http('POST', '/api/v1/content-pages', [
    'title' => ['en' => 'Featured Products', 'ar' => 'المنتجات المميزة'],
], $adminToken);
$cp003 = $json['data']['id'] ?? null;
record('TC-CP-003', $code === 201, 'HTTP=' . $code . ' id=' . $cp003);

// TC-CP-004 missing title -> 422 + zero mutation
$before = DB::table('content_pages')->count();
[$code] = http('POST', '/api/v1/content-pages', [], $adminToken);
$after = DB::table('content_pages')->count();
record('TC-CP-004', $code === 422 && $before === $after, 'HTTP=' . $code . ' before=' . $before . ' after=' . $after);

// TC-CP-005 missing title.en -> 422 + zero mutation
$before = DB::table('content_pages')->count();
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['ar' => 'العربية فقط']], $adminToken);
$after = DB::table('content_pages')->count();
record('TC-CP-005', $code === 422 && $before === $after, 'HTTP=' . $code . ' before=' . $before . ' after=' . $after);

// TC-CP-006 invalid title structure -> 422 + zero mutation
$before = DB::table('content_pages')->count();
[$code] = http('POST', '/api/v1/content-pages', ['title' => 'not an array'], $adminToken);
$after = DB::table('content_pages')->count();
record('TC-CP-006', $code === 422 && $before === $after, 'HTTP=' . $code . ' before=' . $before . ' after=' . $after);

// TC-CP-007 duplicate title.en (-> duplicate slug) -> 422 + zero mutation (UniqueTranslationRule)
$before = DB::table('content_pages')->count();
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Home Electronics']], $adminToken);
$after = DB::table('content_pages')->count();
record('TC-CP-007', $code === 422 && $before === $after, 'HTTP=' . $code . ' before=' . $before . ' after=' . $after . ' (unique-translation guard)');

// TC-CP-008 unauthorized (view-only user cannot create) -> 403 + zero mutation
$before = DB::table('content_pages')->count();
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Nope']], $viewToken);
$after = DB::table('content_pages')->count();
record('TC-CP-008', $code === 403 && $before === $after, 'HTTP=' . $code . ' before=' . $before . ' after=' . $after);

// TC-CP-009 unauthenticated -> 401 + zero mutation
$before = DB::table('content_pages')->count();
[$code] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Nope']]);
$after = DB::table('content_pages')->count();
record('TC-CP-009', $code === 401 && $before === $after, 'HTTP=' . $code . ' before=' . $before . ' after=' . $after);

ev('  AFTER content_pages=' . DB::table('content_pages')->count() . '  (expected +3)');

// =====================================================================
// PHASE 4 — CONTENT PAGE UPDATE (real row diff)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 4 — CONTENT PAGE UPDATE');

$beforeRow = row('content_pages', (int) $cp002);
ev('  BEFORE row: ' . json_encode($beforeRow));
[$code, $json] = http('PUT', '/api/v1/content-pages/' . $cp002, [
    'title' => ['en' => 'Smartphones & Tablets', 'ar' => 'الهواتف الذكية'],
    'is_active' => 0,
], $adminToken);
$afterRow = row('content_pages', (int) $cp002);
ev('  HTTP=' . $code . ' response data.title=' . json_encode($json['data']['title'] ?? null));
ev('  AFTER  row: ' . json_encode($afterRow));
$ok = $code === 200
    && json_decode($afterRow->title, true)['en'] === 'Smartphones & Tablets'
    && json_decode($afterRow->title, true)['ar'] === 'الهواتف الذكية'
    && (int) $afterRow->is_active === 0
    && $afterRow->slug === $beforeRow->slug
    && (string) $afterRow->created_at === (string) $beforeRow->created_at
    && $afterRow->updated_at !== null;
record('TC-CP-U-001', (bool) $ok, 'title+is_active changed, slug/created_at unchanged, updated_at persisted');
record('TC-CP-U-002', isset($json['data']['title']) && $json['data']['title'] === 'Smartphones & Tablets', 'API response title matches DB (en)');

// =====================================================================
// PHASE 5 — CONTENT PAGE DELETE (real behavior: hard delete + FK nullOnDelete)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 5 — CONTENT PAGE DELETE');

[$code, $json] = http('POST', '/api/v1/content-pages', [
    'title' => ['en' => 'Promotions Page', 'ar' => 'صفحة العروض'],
], $adminToken);
$cpDel = $json['data']['id'] ?? null;

// create two orphan sections via API
$sectionIds = [];
foreach ([['en' => 'Hero Banners', 'ar' => 'بانرات رئيسية'], ['en' => 'Active Promotions', 'ar' => 'عروض نشطة']] as $t) {
    [$sc] = http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => $t, 'is_active' => 1, 'title_visible' => 1], $adminToken);
    $sectionIds[] = DB::table('sections')->orderByDesc('id')->value('id');
}
ev('  created sections ids=' . json_encode($sectionIds));
// attach them to the page
[$code] = http('POST', '/api/v1/content-pages/' . $cpDel . '/attach-sections', ['sections' => $sectionIds], $adminToken);
ev('  attach HTTP=' . $code . ' -> DB content_page_id=' . json_encode(DB::table('sections')->whereIn('id', $sectionIds)->pluck('content_page_id')));

$beforeSections = DB::table('sections')->whereIn('id', $sectionIds)->count();
[$code] = http('DELETE', '/api/v1/content-pages/' . $cpDel, [], $adminToken);
$pageGone = row('content_pages', (int) $cpDel) === null;
$orphan = DB::table('sections')->whereIn('id', $sectionIds)->get();
ev('  DELETE HTTP=' . $code);
ev('  sections after delete: ' . json_encode($orphan));
$allNull = DB::table('sections')->whereIn('id', $sectionIds)->whereNotNull('content_page_id')->count() === 0;
record('TC-CP-D-001', $code === 200 && $pageGone, 'hard delete confirmed');
record('TC-CP-D-002', $allNull && DB::table('sections')->whereIn('id', $sectionIds)->count() === $beforeSections, 'sections survive as orphans (content_page_id=NULL) — FK nullOnDelete');

// =====================================================================
// PHASE 6 — PUBLIC ACTIVE / INACTIVE
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 6 — PUBLIC ACTIVE / INACTIVE');

// create an inactive page explicitly: 'Offers Archive' then toggle inactive
[$code, $json] = http('POST', '/api/v1/content-pages', [
    'title' => ['en' => 'Offers Archive', 'ar' => 'أرشيف العروض'],
], $adminToken);
$cpInactive = $json['data']['id'] ?? null;
[$code] = http('PATCH', '/api/v1/content-pages/' . $cpInactive . '/toggle-active', [], $adminToken);
$inactiveRow = row('content_pages', (int) $cpInactive);
ev('  inactive page id=' . $cpInactive . ' toggle HTTP=' . $code . ' DB is_active=' . $inactiveRow->is_active);

// both physically exist in DB
$activeRow = row('content_pages', (int) $cp001);
record('TC-PB-DB-001', $activeRow !== null && (int) $activeRow->is_active === 1, 'active page exists is_active=1');
record('TC-PB-DB-002', $inactiveRow !== null && (int) $inactiveRow->is_active === 0, 'inactive page exists is_active=0');

[$code, $json] = http('GET', '/api/v1/general/content-pages');
$pubSlugs = array_column($json['data'] ?? [], 'slug');
ev('  public index slugs: ' . json_encode($pubSlugs));
record('TC-PB-001', $code === 200 && in_array('home-electronics', $pubSlugs, true) && !in_array('offers-archive', $pubSlugs, true), 'active appears, inactive hidden');

[$code, $json] = http('GET', '/api/v1/general/content-pages/home-electronics');
record('TC-PB-002', $code === 200, 'active show HTTP=' . $code);
[$code] = http('GET', '/api/v1/general/content-pages/offers-archive');
record('TC-PB-003', $code === 404, 'inactive show hidden HTTP=' . $code);