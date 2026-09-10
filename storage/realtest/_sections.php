<?php

// =====================================================================
// PHASE 9 — SECTION CREATE
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 9 — SECTION CREATE');

$secCountBefore = DB::table('sections')->count();

$secTitles = [
    ['type' => 'banners', 'title' => ['en' => 'Hero Banners', 'ar' => 'بانرات رئيسية'], 'setting' => ['front' => ['heading' => 'Featured'], 'back' => []]],
    ['type' => 'categories', 'title' => ['en' => 'Featured Categories', 'ar' => 'التصنيفات المميزة'], 'setting' => null],
    ['type' => 'products', 'title' => ['en' => 'Best Selling Products', 'ar' => 'الأكثر مبيعاً'], 'setting' => ['front' => [], 'back' => ['limit' => 5, 'type' => 'best_selling']]],
    ['type' => 'products', 'title' => ['en' => 'New Arrivals', 'ar' => 'وصل حديثاً'], 'setting' => ['front' => [], 'back' => ['limit' => 5, 'type' => 'new_arrivals']]],
    ['type' => 'brands', 'title' => ['en' => 'Featured Brands', 'ar' => 'علامات مميزة'], 'setting' => null],
    ['type' => 'promotions', 'title' => ['en' => 'Active Promotions', 'ar' => 'عروض نشطة'], 'setting' => null],
];
$sectionIds = [];
foreach ($secTitles as $i => $spec) {
    $payload = ['type' => $spec['type'], 'title' => $spec['title'], 'is_active' => 1, 'title_visible' => 1];
    if ($spec['setting'] !== null) {
        $payload['setting'] = $spec['setting'];
    }
    [$code, $json] = http('POST', '/api/v1/sections', $payload, $adminToken);
    $id = $json['data']['id'] ?? null;
    $sectionIds[$spec['title']['en']] = $id;
    ev('  section "' . $spec['title']['en'] . '" HTTP=' . $code . ' id=' . $id);
}
$secCountAfter = DB::table('sections')->count();
$secRows = DB::table('sections')->whereIn('id', array_values(array_filter($sectionIds, 'is_int')))->orderBy('id')->get();
foreach ($secRows as $r) {
    ev('  DB section row: ' . json_encode($r));
}
record('TC-SC-001', $secCountAfter === $secCountBefore + 6, 'sections before=' . $secCountBefore . ' after=' . $secCountAfter . ' (expected +6)');
$hero = DB::table('sections')->where('id', $sectionIds['Hero Banners'])->first();
$expectedOrder = (int) DB::table('sections')->where('id', '<', $hero->id)->max('order') + 1;
record('TC-SC-002', $hero !== null && $hero->type === 'banners'
    && json_decode($hero->title, true)['en'] === 'Hero Banners'
    && json_decode($hero->title, true)['ar'] === 'بانرات رئيسية'
    && (int) $hero->is_active === 1
    && (int) $hero->title_visible === 1
    && (int) $hero->order === $expectedOrder, 'every persisted field verified for Hero Banners (order auto-assigned=' . $expectedOrder . ')');

// =====================================================================
// PHASE 10 — ATTACH / DETACH
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 10 — ATTACH / DETACH');

[$code, $json] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Storefront Home', 'ar' => 'الواجهة الرئيسية']], $adminToken);
$homePage = $json['data']['id'] ?? null;

// 3 orphan sections
$orphan = [];
foreach ([['en' => 'Section One', 'ar' => 'قسم واحد'], ['en' => 'Section Two', 'ar' => 'قسم اثنان'], ['en' => 'Section Three', 'ar' => 'قسم ثلاثة']] as $t) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => $t, 'is_active' => 1], $adminToken);
    $orphan[] = $sj['data']['id'];
}
ev('  orphan sections before attach: content_page_id=' . json_encode(DB::table('sections')->whereIn('id', $orphan)->pluck('content_page_id')));

[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => $orphan], $adminToken);
$mapping = DB::table('sections')->whereIn('id', $orphan)->orderBy('id')->get(['id', 'content_page_id']);
ev('  after attach HTTP=' . $code . ' : ' . json_encode($mapping));
$allAttached = DB::table('sections')->whereIn('id', $orphan)->where('content_page_id', $homePage)->count() === 3;
record('TC-AT-001', $code === 200 && $allAttached, 'sections 101/102/103 -> page content_page_id');

// additive re-attach of subset (documented contract: attach is additive; [] detaches all)
[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [$orphan[0], $orphan[1]]], $adminToken);
$stillAttached = DB::table('sections')->whereIn('id', $orphan)->where('content_page_id', $homePage)->count();
record('TC-AT-002', $code === 200 && $stillAttached === 3, 'subset re-attach is additive; no partial-detach endpoint in contract');

// unrelated orphan untouched
[$code, $json] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Unrelated Section', 'ar' => 'قسم غير مرتبط'], 'is_active' => 1], $adminToken);
$unrelated = $json['data']['id'];
record('TC-AT-003', DB::table('sections')->where('id', $unrelated)->value('content_page_id') === null, 'unrelated section NOT modified');

// detach all
[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => []], $adminToken);
$afterDetach = DB::table('sections')->whereIn('id', $orphan)->whereNull('content_page_id')->count();
record('TC-AT-004', $code === 200 && $afterDetach === 3, 'detach-all: content_page_id=NULL for exactly the 3 expected sections');

// invalid attach -> 422 + zero mutation
$before = DB::table('sections')->whereIn('id', $orphan)->pluck('content_page_id')->all();
[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [999999]], $adminToken);
$after = DB::table('sections')->whereIn('id', $orphan)->pluck('content_page_id')->all();
record('TC-AT-005', $code === 422 && $before === $after, 'invalid section id HTTP=' . $code . ' zero mutation');

// =====================================================================
// PHASE 11 — REORDER EXTREME
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 11 — REORDER EXTREME');

// 4 fresh sections on a dedicated page
[$code, $json] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Reorder Page', 'ar' => 'صفحة الترتيب']], $adminToken);
$reorderPage = $json['data']['id'];
$sec = [];
foreach ([['en' => 'Section A', 'ar' => 'أ'], ['en' => 'Section B', 'ar' => 'ب'], ['en' => 'Section C', 'ar' => 'ج'], ['en' => 'Section D', 'ar' => 'د']] as $t) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => $t, 'is_active' => 1], $adminToken);
    $sec[] = $sj['data']['id'];
}
[$code] = http('POST', '/api/v1/content-pages/' . $reorderPage . '/attach-sections', ['sections' => $sec], $adminToken);
$beforeOrder = DB::table('sections')->whereIn('id', $sec)->orderBy('order')->pluck('order', 'id')->all();
ev('  BEFORE order: ' . json_encode($beforeOrder));

[$code] = http('POST', '/api/v1/sections/reorder', ['sections' => [$sec[3], $sec[1], $sec[0], $sec[2]]], $adminToken);
$afterOrder = DB::table('sections')->whereIn('id', $sec)->orderBy('order')->get(['id', 'order']);
ev('  AFTER reorder HTTP=' . $code . ' : ' . json_encode($afterOrder));
$orderMap = [];
foreach ($afterOrder as $o) {
    $orderMap[$o->id] = (int) $o->order;
}
record('TC-RO-001', $code === 200 && $orderMap == [$sec[3] => 1, $sec[1] => 2, $sec[0] => 3, $sec[2] => 4], 'D=1 B=2 A=3 C=4 persisted in DB');

// public API returns same order
$slug = DB::table('content_pages')->where('id', $reorderPage)->value('slug');
[$code, $json, $raw] = http('GET', '/api/v1/general/content-pages/' . $slug);
$pubOrder = array_column($json['data']['sections'] ?? [], 'id');
ev('  public order HTTP=' . $code . ': ' . json_encode($pubOrder));
ev('  public raw: ' . substr($raw, 0, 400));
record('TC-RO-002', $code === 200 && $pubOrder == [$sec[3], $sec[1], $sec[0], $sec[2]], 'public API returns same DB order');

// invalid reorder cases: each 422 + zero mutation
$invalid = [
    'empty array' => ['sections' => []],
    'duplicate ids' => ['sections' => [$sec[0], $sec[0], $sec[1]]],
    'unknown id' => ['sections' => [999999, $sec[0]]],
    'string id' => ['sections' => ['abc', $sec[0]]],
    'float id' => ['sections' => [1.5, $sec[0]]],
    'null id' => ['sections' => [null, $sec[0]]],
    'missing sections' => [],
    'wrong type' => ['sections' => 'not-an-array'],
];
$snapshotOrder = DB::table('sections')->whereIn('id', $sec)->orderBy('id')->pluck('order', 'id')->all();
$allOk = true;
foreach ($invalid as $label => $payload) {
    [$code] = http('POST', '/api/v1/sections/reorder', $payload, $adminToken);
    $nowOrder = DB::table('sections')->whereIn('id', $sec)->orderBy('id')->pluck('order', 'id')->all();
    $ok = $code === 422 && $nowOrder === $snapshotOrder;
    if (!$ok) {
        $allOk = false;
    }
    ev('  invalid reorder (' . $label . '): HTTP=' . $code . ' orderUnchanged=' . ($nowOrder === $snapshotOrder ? 'yes' : 'NO'));
}
record('TC-RO-003', $allOk, '8 invalid cases -> 422 + ZERO order mutation');

// =====================================================================
// PHASE 12 — TOGGLE STATUS
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 12 — TOGGLE STATUS');

$toggleSec = $sectionIds['Hero Banners'];
[$code, $json] = http('PATCH', '/api/v1/sections/' . $toggleSec . '/toggle-active', [], $adminToken);
$rowAfterOff = DB::table('sections')->where('id', $toggleSec)->first();
ev('  toggle OFF HTTP=' . $code . ' DB is_active=' . $rowAfterOff->is_active);
record('TC-TG-001', $code === 200 && (int) $rowAfterOff->is_active === 0, 'DB is_active=0');

[$code, $json] = http('PATCH', '/api/v1/sections/' . $toggleSec . '/toggle-active', [], $adminToken);
$rowAfterOn = DB::table('sections')->where('id', $toggleSec)->first();
record('TC-TG-002', $code === 200 && (int) $rowAfterOn->is_active === 1, 'DB is_active back to 1');

// =====================================================================
// PHASE 13 — SECTION UPDATE (field-by-field diff)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 13 — SECTION UPDATE');

$updSec = $sectionIds['Active Promotions'];
$beforeRow = DB::table('sections')->where('id', $updSec)->first();
ev('  BEFORE: ' . json_encode($beforeRow));
[$code, $json] = http('PUT', '/api/v1/sections/' . $updSec, [
    'title' => ['en' => 'Hot Promotions', 'ar' => 'عروض ساخنة'],
    'is_active' => 0,
    'title_visible' => 0,
    'setting' => ['front' => ['badge' => 'HOT'], 'back' => []],
], $adminToken);
$afterRow = DB::table('sections')->where('id', $updSec)->first();
ev('  HTTP=' . $code . ' AFTER: ' . json_encode($afterRow));
$afterSetting = json_decode($afterRow->setting, true) ?: [];
$diff = [];
if (json_decode($afterRow->title, true)['en'] !== 'Hot Promotions') { $diff[] = 'title'; }
if ((int) $afterRow->is_active !== 0) { $diff[] = 'is_active'; }
if ((int) $afterRow->title_visible !== 0) { $diff[] = 'title_visible'; }
if (($afterSetting['front']['badge'] ?? null) !== 'HOT') { $diff[] = 'setting'; }
if ($afterRow->content_page_id !== $beforeRow->content_page_id) { $diff[] = 'content_page_id'; }
if ($afterRow->type !== $beforeRow->type) { $diff[] = 'type'; }
record('TC-SU-001', $code === 200 && count($diff) === 0, 'title/is_active/title_visible/setting updated; content_page_id + type unchanged ' . json_encode($diff));

// =====================================================================
// PHASE 14 — SECTION DELETE
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 14 — SECTION DELETE');

$delSec = $unrelated;
$beforeCount = DB::table('sections')->count();
[$code] = http('DELETE', '/api/v1/sections/' . $delSec, [], $adminToken);
$afterCount = DB::table('sections')->count();
$rowGone = DB::table('sections')->where('id', $delSec)->count() === 0;
record('TC-SD-001', $code === 200 && $rowGone && $afterCount === $beforeCount - 1, 'hard delete; sections ' . $beforeCount . ' -> ' . $afterCount);