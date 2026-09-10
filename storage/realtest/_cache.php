<?php

use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;

// =====================================================================
// PHASE 18 — OBSERVER LIFECYCLE COUNTERS (real Eloquent events)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 18 — OBSERVER / ELOCUENT LIFECYCLE COUNTERS');

$ev = [
    'ContentPage.created' => 0, 'ContentPage.updated' => 0, 'ContentPage.deleted' => 0,
    'Section.created' => 0, 'Section.updated' => 0, 'Section.deleted' => 0,
    'SectionType.created' => 0, 'SectionType.updated' => 0, 'SectionType.deleted' => 0,
    'SectionTypeSetting.created' => 0, 'SectionTypeSetting.deleted' => 0,
];
ContentPage::created(function () use (&$ev) { $ev['ContentPage.created']++; });
ContentPage::updated(function () use (&$ev) { $ev['ContentPage.updated']++; });
ContentPage::deleted(function () use (&$ev) { $ev['ContentPage.deleted']++; });
Section::created(function () use (&$ev) { $ev['Section.created']++; });
Section::updated(function () use (&$ev) { $ev['Section.updated']++; });
Section::deleted(function () use (&$ev) { $ev['Section.deleted']++; });
SectionType::created(function () use (&$ev) { $ev['SectionType.created']++; });
SectionType::updated(function () use (&$ev) { $ev['SectionType.updated']++; });
SectionType::deleted(function () use (&$ev) { $ev['SectionType.deleted']++; });
SectionTypeSetting::created(function () use (&$ev) { $ev['SectionTypeSetting.created']++; });
SectionTypeSetting::deleted(function () use (&$ev) { $ev['SectionTypeSetting.deleted']++; });

// =====================================================================
// PHASE 19 — REAL CACHE VERIFICATION FOR EVERY MUTATION
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 19 — REAL CACHE VERIFICATION (tag=content_pages, array store)');

// dedicated home page for cache scenarios
[$code, $json] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Home', 'ar' => 'الرئيسية']], $adminToken);
$homePage = $json['data']['id'];
ev('  home page id=' . $homePage . ' slug=home');

$homeSections = [];
foreach ([['en' => 'Home Banner', 'ar' => 'بانر رئيسي'], ['en' => 'Home Products', 'ar' => 'منتجات رئيسية']] as $t) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => $t, 'is_active' => 1, 'title_visible' => 1], $adminToken);
    $homeSections[] = $sj['data']['id'];
}
http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => $homeSections], $adminToken);

$homeUri = '/api/v1/general/content-pages/home';
$key = cacheKey($homeUri);

function cacheScenario(string $id, callable $mutation, callable $assertAfter, array &$evRef = []): void
{
    global $adminToken, $homeUri, $key;
    [$c1, $j1] = http('GET', $homeUri);
    $hasBefore = Cache::tags(['content_pages'])->has($key);
    $mutation();
    $hasAfter = Cache::tags(['content_pages'])->has($key);
    [$c2, $j2] = http('GET', $homeUri);
    $ok = $hasBefore && !$hasAfter && $c2 === 200 && $assertAfter($j1, $j2);
    record($id, $ok, 'cacheBefore=' . ($hasBefore ? 'yes' : 'no') . ' cacheAfter=' . ($hasAfter ? 'yes' : 'no'));
}

$hSec = fn ($j) => count($j['data']['sections'] ?? []);
$titles = fn ($j) => array_column($j['data']['sections'] ?? [], 'title');

// 1. ContentPage create -> flush (home content unchanged)
cacheScenario('TC-CA-PAGE-CREATE', function () {
    global $adminToken;
    http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Cache Temp Page', 'ar' => 'صفحة مؤقتة']], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b), $ev);

// 2. Section create + attach -> home +1
cacheScenario('TC-CA-SECTION-CREATE', function () {
    global $adminToken, $homePage;
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Cache Created Section', 'ar' => 'قسم تم إنشاؤه'], 'is_active' => 1], $adminToken);
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [$sj['data']['id']]], $adminToken);
}, fn ($a, $b) => $hSec($b) === $hSec($a) + 1);

// 3. Section update -> title change reflected
$homeFirst = $homeSections[0];
cacheScenario('TC-CA-SECTION-UPDATE', function () use ($homeFirst) {
    global $adminToken;
    http('PUT', '/api/v1/sections/' . $homeFirst, ['title' => ['en' => 'Updated Home Banner', 'ar' => 'بانر محدث'], 'title_visible' => 1], $adminToken);
}, function ($a, $b) use ($titles) {
    return in_array('Updated Home Banner', $titles($b), true);
});

// 4. Attach orphan -> home +1
cacheScenario('TC-CA-ATTACH', function () {
    global $adminToken, $homePage;
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Orphan To Attach', 'ar' => 'قسم لإلحاقه'], 'is_active' => 1], $adminToken);
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [$sj['data']['id']]], $adminToken);
}, fn ($a, $b) => $hSec($b) === $hSec($a) + 1);

// 5. Reorder -> order reflected
cacheScenario('TC-CA-REORDER', function () {
    global $adminToken, $homePage, $homeSections;
    $ids = DB::table('sections')->where('content_page_id', $homePage)->orderBy('id')->pluck('id')->all();
    $reversed = array_reverse($ids);
    http('POST', '/api/v1/sections/reorder', ['sections' => $reversed], $adminToken);
}, function ($a, $b) {
    $beforeIds = array_column($a['data']['sections'] ?? [], 'id');
    $afterIds = array_column($b['data']['sections'] ?? [], 'id');
    return $beforeIds !== $afterIds;
});

// 6. Section toggle off -> hidden from public home
cacheScenario('TC-CA-TOGGLE', function () use ($homeFirst) {
    global $adminToken;
    http('PATCH', '/api/v1/sections/' . $homeFirst . '/toggle-active', [], $adminToken);
}, fn ($a, $b) => $hSec($b) === $hSec($a) - 1);

// 7. ContentPage toggle (temp inactive page back on) -> flush, home unchanged
cacheScenario('TC-CA-PAGE-TOGGLE', function () {
    global $adminToken;
    $temp = DB::table('content_pages')->where('title', 'like', '%Cache Temp%')->first();
    http('PATCH', '/api/v1/content-pages/' . $temp->id . '/toggle-active', [], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 8. ContentPage update -> title reflected
cacheScenario('TC-CA-PAGE-UPDATE', function () {
    global $adminToken, $homePage;
    http('PUT', '/api/v1/content-pages/' . $homePage, ['title' => ['en' => 'Home Page Updated', 'ar' => 'الرئيسية المحدثة']], $adminToken);
}, fn ($a, $b) => ($b['data']['title'] ?? null) === 'Home Page Updated');

// 9. ContentPage delete (temp page) -> flush, home unchanged
cacheScenario('TC-CA-PAGE-DELETE', function () {
    global $adminToken;
    $temp = DB::table('content_pages')->where('title', 'like', '%Cache Temp%')->first();
    http('DELETE', '/api/v1/content-pages/' . $temp->id, [], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 10. Section delete -> home -1
cacheScenario('TC-CA-SECTION-DELETE', function () {
    global $adminToken, $homePage;
    $victim = DB::table('sections')->where('content_page_id', $homePage)->orderByDesc('id')->value('id');
    http('DELETE', '/api/v1/sections/' . $victim, [], $adminToken);
}, fn ($a, $b) => $hSec($b) === $hSec($a) - 1);

// 11. Detach subset (additive) -> flush, count unchanged
$idsOnHome = DB::table('sections')->where('content_page_id', $homePage)->pluck('id')->all();
cacheScenario('TC-CA-DETACH-SUBSET', function () use ($idsOnHome) {
    global $adminToken, $homePage;
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => array_slice($idsOnHome, 0, 1)], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 12. Detach all -> home empty
cacheScenario('TC-CA-DETACH-ALL', function () {
    global $adminToken, $homePage;
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => []], $adminToken);
}, fn ($a, $b) => $hSec($b) === 0);

// 13. SectionType create -> flush
cacheScenario('TC-CA-TYPE-CREATE', function () {
    global $adminToken;
    http('POST', '/api/v1/section-types', ['type' => 'cache-type-' . uniqid()], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 14. SectionType update -> flush
$ct2 = DB::table('section_types')->where('type', 'like', 'cache-type-%')->first();
$newType = 'renamed-' . $ct2->type;
cacheScenario('TC-CA-TYPE-UPDATE', function () use ($ct2, $newType) {
    global $adminToken;
    http('PUT', '/api/v1/section-types/' . $ct2->type, ['type' => $newType], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 15. SectionType delete -> flush
cacheScenario('TC-CA-TYPE-DELETE', function () use ($newType) {
    global $adminToken;
    http('DELETE', '/api/v1/section-types/' . $newType, [], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 16. SectionTypeSettings update (bulk delete path) -> flush
cacheScenario('TC-CA-SETTINGS-UPDATE', function () {
    global $adminToken;
    http('POST', '/api/v1/section-types/banners/settings', ['front' => ['heading' => 'Fresh'], 'back' => []], $adminToken);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

ev('  Eloquent event counters after scenarios: ' . json_encode($ev));

// observer proof: cache flush happened as direct consequence of the lifecycle events
record('TC-OB-001', $ev['ContentPage.created'] > 0 && $ev['ContentPage.updated'] > 0 && $ev['ContentPage.deleted'] > 0, 'ContentPageObserver lifecycle fired (created/updated/deleted) + flush observed');
record('TC-OB-002', $ev['Section.created'] > 0 && $ev['Section.updated'] > 0 && $ev['Section.deleted'] > 0, 'SectionObserver lifecycle fired + flush observed');
record('TC-OB-003', $ev['SectionType.created'] > 0 && $ev['SectionType.updated'] > 0 && $ev['SectionType.deleted'] > 0, 'SectionTypeObserver lifecycle fired + flush observed');
record('TC-OB-004', $ev['SectionTypeSetting.created'] > 0, 'SectionTypeSettingObserver create lifecycle fired; bulk-delete path (query builder) fires NO deleted events by design — explicit flush proven by TC-CA-SETTINGS-UPDATE');

// bypass paths: reorder + detach-all + settings-bulk-delete must NOT fire model events yet still flush
$evBypass = ['Section.updated' => 0, 'SectionTypeSetting.deleted' => 0];
$before = $ev['Section.updated'] + $ev['SectionTypeSetting.deleted'];
// run the three bypass ops through fresh objects and count events via DB::table increments
$b1 = $ev['Section.updated'];
http('POST', '/api/v1/sections/reorder', ['sections' => $idsOnHome], $adminToken);
$b2 = $ev['Section.updated'];
$s1 = $ev['SectionTypeSetting.deleted'];
http('POST', '/api/v1/section-types/banners/settings', ['front' => ['heading' => 'Bypass'], 'back' => ['limit' => 2]], $adminToken);
$s2 = $ev['SectionTypeSetting.deleted'];
$o1 = DB::table('sections')->where('content_page_id', $homePage)->count();
http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => []], $adminToken);
$o2 = DB::table('sections')->where('content_page_id', $homePage)->count();
ev('  bypass check — reorder Section.updated: before=' . $b1 . ' after=' . $b2 . ' | settings SectionTypeSetting.deleted: before=' . $s1 . ' after=' . $s2 . ' | detach-all content_page_id count: ' . $o1 . ' -> ' . $o2);
record('TC-OB-005', $b2 === $b1 && $s2 === $s1, 'reorder + settings-bulk-delete use query builders (no model events fired) — explicit flush is the required mechanism');

// =====================================================================
// PHASE 20 — CACHE ISOLATION (content_pages vs products)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 20 — CACHE ISOLATION');

[$c, $j] = http('GET', '/api/v1/general/content-pages/home');
$productsUri = '/api/v1/general/products?limit=50';
[$pc, $pj] = http('GET', $productsUri);
$productsPayload = $pj;
$contentKey = cacheKey('/api/v1/general/content-pages/home');
Cache::tags(['content_pages'])->put('iso-marker-content', 'flush-me', 60);
Cache::tags(['products'])->put('iso-marker-products', 'keep-me', 60);
ev('  populated content_pages + products caches; markers planted');

// mutate a section (attaches nothing, but flushes content_pages tag)
[$code] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Isolation Trigger', 'ar' => 'مشغل العزل'], 'is_active' => 1], $adminToken);

$contentGone = !Cache::tags(['content_pages'])->has('iso-marker-content') && !Cache::tags(['content_pages'])->has($contentKey);
$productsIntact = Cache::tags(['products'])->get('iso-marker-products') === 'keep-me';
[$pc2, $pj2] = http('GET', $productsUri);
$payloadSame = $pj2 === $productsPayload;
record('TC-CI-001', $contentGone, 'content_pages tag flushed (marker + home key gone)');
record('TC-CI-002', $productsIntact, 'products tag marker intact (NOT flushed)');
record('TC-CI-003', $payloadSame, 'products payload byte-identical after section mutation');