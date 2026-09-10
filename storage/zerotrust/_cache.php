<?php

use Marvel\Models\ContentPage;
use Marvel\Models\Section;
use Marvel\Database\Models\SectionType;
use Marvel\Database\Models\SectionTypeSetting;

// =====================================================================
// PHASE 13 — CACHE / ISOLATION / OBSERVERS / N+1
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 13 — OBSERVER LIFECYCLE COUNTERS');

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

ev('');
ev('=================================================================');
ev('PHASE 13b — REAL CACHE VERIFICATION (16 scenarios)');

[$code, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Cache Home', 'ar' => 'الرئيسية']], $GLOBALS['adminToken']);
$homePage = $j['data']['id'];
$homeSections = [];
foreach ([['en' => 'CBanner', 'ar' => 'بانر'], ['en' => 'CProducts', 'ar' => 'منتجات']] as $t) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => $t, 'is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);
    $homeSections[] = $sj['data']['id'];
}
http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => $homeSections], $GLOBALS['adminToken']);

$homeUri = '/api/v1/general/content-pages/cache-home';
$key = cacheKey($homeUri);
$GLOBALS['hSec'] = fn ($j) => count($j['data']['sections'] ?? []);
$GLOBALS['titlesOf'] = fn ($j) => array_column($j['data']['sections'] ?? [], 'title');

function ztCacheScenario(string $id, callable $mutation, callable $assertAfter): void
{
    global $homeUri, $key;
    [$c1, $j1] = http('GET', $homeUri);
    $cacheBefore = Cache::tags(['content_pages'])->has($key);
    $dbBefore = DB::table('sections')->where('content_page_id', (int) DB::table('content_pages')->where('slug', 'cache-home')->value('id'))->count();
    $mutation();
    $cacheAfter = Cache::tags(['content_pages'])->has($key);
    $dbAfter = DB::table('sections')->where('content_page_id', (int) DB::table('content_pages')->where('slug', 'cache-home')->value('id'))->count();
    [$c2, $j2] = http('GET', $homeUri);
    $ok = $cacheBefore && !$cacheAfter && $c2 === 200 && $assertAfter($j1, $j2);
    record($id, $ok, 'cacheBefore=' . ($cacheBefore ? 'yes' : 'no') . ' cacheAfter=' . ($cacheAfter ? 'no' : 'yes') . ' DBBefore=' . $dbBefore . ' DBAfter=' . $dbAfter);
}

// 1 page create
ztCacheScenario('TC-CA-PAGE-CREATE', function () {
    http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Cache Temp', 'ar' => 'مؤقتة']], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 2 section create + attach
ztCacheScenario('TC-CA-SECTION-CREATE', function () {
    global $homePage;
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Cache New', 'ar' => 'جديد'], 'is_active' => 1], $GLOBALS['adminToken']);
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [$sj['data']['id']]], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($b) === $hSec($a) + 1);

// 3 section update
$first = $homeSections[0];
ztCacheScenario('TC-CA-SECTION-UPDATE', function () use ($first) {
    http('PUT', '/api/v1/sections/' . $first, ['title' => ['en' => 'Renamed CBanner', 'ar' => 'بانر']], $GLOBALS['adminToken']);
}, fn ($a, $b) => in_array('Renamed CBanner', $titlesOf($b), true));

// 4 attach orphan
ztCacheScenario('TC-CA-ATTACH', function () use ($homePage) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'To Attach', 'ar' => 'إلحاق'], 'is_active' => 1], $GLOBALS['adminToken']);
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [$sj['data']['id']]], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($b) === $hSec($a) + 1);

// 5 reorder
ztCacheScenario('TC-CA-REORDER', function () use ($homePage) {
    $ids = DB::table('sections')->where('content_page_id', $homePage)->orderBy('id')->pluck('id')->all();
    http('POST', '/api/v1/sections/reorder', ['sections' => array_reverse($ids)], $GLOBALS['adminToken']);
}, fn ($a, $b) => array_column($a['data']['sections'] ?? [], 'id') !== array_column($b['data']['sections'] ?? [], 'id'));

// 6 section toggle
ztCacheScenario('TC-CA-TOGGLE', function () use ($first) {
    http('PATCH', '/api/v1/sections/' . $first . '/toggle-active', [], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($b) === $hSec($a) - 1);

// 7 page toggle
ztCacheScenario('TC-CA-PAGE-TOGGLE', function () {
    $tmp = DB::table('content_pages')->where('slug', 'cache-temp')->value('id');
    http('PATCH', '/api/v1/content-pages/' . $tmp . '/toggle-active', [], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 8 page update
ztCacheScenario('TC-CA-PAGE-UPDATE', function () use ($homePage) {
    http('PUT', '/api/v1/content-pages/' . $homePage, ['title' => ['en' => 'Cache Home Updated', 'ar' => 'الرئيسية']], $GLOBALS['adminToken']);
}, fn ($a, $b) => ($b['data']['title'] ?? null) === 'Cache Home Updated');

// 9 page delete
ztCacheScenario('TC-CA-PAGE-DELETE', function () {
    $tmp = DB::table('content_pages')->where('slug', 'cache-temp')->value('id');
    http('DELETE', '/api/v1/content-pages/' . $tmp, [], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 10 section delete
ztCacheScenario('TC-CA-SECTION-DELETE', function () use ($homePage) {
    $victim = DB::table('sections')->where('content_page_id', $homePage)->orderByDesc('id')->value('id');
    http('DELETE', '/api/v1/sections/' . $victim, [], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($b) === $hSec($a) - 1);

// 11 detach subset (additive -> no change)
$idsOnHome = DB::table('sections')->where('content_page_id', $homePage)->pluck('id')->all();
ztCacheScenario('TC-CA-DETACH-SUBSET', function () use ($idsOnHome, $homePage) {
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => array_slice($idsOnHome, 0, 1)], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 12 detach all
ztCacheScenario('TC-CA-DETACH-ALL', function () use ($homePage) {
    http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => []], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($b) === 0);

// 13 type create
ztCacheScenario('TC-CA-TYPE-CREATE', function () {
    http('POST', '/api/v1/section-types', ['type' => 'cache-type-' . uniqid()], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 14 type update
$ct2 = DB::table('section_types')->where('type', 'like', 'cache-type-%')->first();
$newType = 'renamed-' . $ct2->type;
ztCacheScenario('TC-CA-TYPE-UPDATE', function () use ($ct2, $newType) {
    http('PUT', '/api/v1/section-types/' . $ct2->type, ['type' => $newType], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 15 type delete
ztCacheScenario('TC-CA-TYPE-DELETE', function () use ($newType) {
    http('DELETE', '/api/v1/section-types/' . $newType, [], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

// 16 settings update
ztCacheScenario('TC-CA-SETTINGS-UPDATE', function () {
    http('POST', '/api/v1/section-types/banners/settings', ['front' => ['heading' => 'Fresh'], 'back' => []], $GLOBALS['adminToken']);
}, fn ($a, $b) => $hSec($a) === $hSec($b));

ev('  Eloquent counters: ' . json_encode($ev));
record('TC-OB-001', $ev['ContentPage.created'] > 0 && $ev['ContentPage.updated'] > 0 && $ev['ContentPage.deleted'] > 0, 'ContentPage events fired (created/updated/deleted)');
record('TC-OB-002', $ev['Section.created'] > 0 && $ev['Section.updated'] > 0 && $ev['Section.deleted'] > 0, 'Section events fired');
record('TC-OB-003', $ev['SectionType.created'] > 0 && $ev['SectionType.updated'] > 0 && $ev['SectionType.deleted'] > 0, 'SectionType events fired');
record('TC-OB-004', $ev['SectionTypeSetting.created'] > 0, 'SectionTypeSetting create event fired; bulk-delete fires none (see bypass)');

$b1 = $ev['Section.updated'];
http('POST', '/api/v1/sections/reorder', ['sections' => $idsOnHome], $GLOBALS['adminToken']);
$b2 = $ev['Section.updated'];
$s1 = $ev['SectionTypeSetting.deleted'];
http('POST', '/api/v1/section-types/banners/settings', ['front' => ['heading' => 'Bypass'], 'back' => ['limit' => 2]], $GLOBALS['adminToken']);
$s2 = $ev['SectionTypeSetting.deleted'];
ev('  bypass: reorder Section.updated ' . $b1 . '->' . $b2 . ' | settings SectionTypeSetting.deleted ' . $s1 . '->' . $s2);
record('TC-OB-005', $b2 === $b1 && $s2 === $s1, 'reorder + settings bulk-delete fire NO model events; explicit flush proven by cache scenarios');

// =====================================================================
// PHASE 13c — CACHE ISOLATION (content_pages vs products)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 13c — CACHE ISOLATION');

[$c, $j] = http('GET', '/api/v1/general/content-pages/cache-home');
$productsUri = '/api/v1/general/products?limit=50';
[$pc, $pj] = http('GET', $productsUri);
$contentKey = cacheKey('/api/v1/general/content-pages/cache-home');
Cache::tags(['content_pages'])->put('iso-marker-c', 'flush-me', 60);
Cache::tags(['products'])->put('iso-marker-p', 'keep-me', 60);
http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Isolation Trigger', 'ar' => 'عزل'], 'is_active' => 1], $GLOBALS['adminToken']);
$contentGone = !Cache::tags(['content_pages'])->has('iso-marker-c') && !Cache::tags(['content_pages'])->has($contentKey);
$productsIntact = Cache::tags(['products'])->get('iso-marker-p') === 'keep-me';
[$pc2, $pj2] = http('GET', $productsUri);
record('TC-CI-001', $contentGone, 'content_pages tag flushed (marker + home key gone)');
record('TC-CI-002', $productsIntact, 'products tag marker intact (NOT flushed)');
record('TC-CI-003', $pj2 === $pj, 'products payload byte-identical after section mutation');

// =====================================================================
// PHASE 13d — N+1 QUERY COUNTS
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 13d — N+1 QUERY COUNTS');

// public page show with >=10 sections
$n1Page = $homePage; // has sections attached (re-attach a bunch)
$addIds = [];
for ($i = 0; $i < 10; $i++) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'N1 Sec ' . $i, 'ar' => 'ن'], 'is_active' => 1], $GLOBALS['adminToken']);
    $addIds[] = $sj['data']['id'];
}
http('POST', '/api/v1/content-pages/' . $n1Page . '/attach-sections', ['sections' => $addIds], $GLOBALS['adminToken']);
$secCount = DB::table('sections')->where('content_page_id', $n1Page)->count();

DB::flushQueryLog();
DB::enableQueryLog();
[$code, $j] = http('GET', '/api/v1/general/content-pages/cache-home');
$log = DB::getQueryLog();
$total = count($log);
$secQueries = count(array_filter($log, fn ($q) => str_contains($q['query'], '`sections`')));
ev('  public page show (sections=' . $secCount . '): totalQueries=' . $total . ' sectionsQueries=' . $secQueries);
record('TC-N1-001', $code === 200 && $secQueries <= 2, 'no per-section queries: ' . $secQueries . ' query(ies) on sections table for ' . $secCount . ' sections');
DB::flushQueryLog();

// public index
DB::enableQueryLog();
[$code, $j] = http('GET', '/api/v1/general/content-pages');
$log = DB::getQueryLog();
$pageCount = DB::table('content_pages')->count();
ev('  public index (pages=' . $pageCount . '): totalQueries=' . count($log));
record('TC-N1-002', $code === 200 && count($log) <= 12, 'index query count constant (' . count($log) . ')');
DB::flushQueryLog();

// admin sections index
DB::enableQueryLog();
[$code, $j] = http('GET', '/api/v1/sections', null, $GLOBALS['adminToken']);
$log = DB::getQueryLog();
$secTotal = DB::table('sections')->count();
ev('  admin sections index (sections=' . $secTotal . '): totalQueries=' . count($log));
record('TC-N1-003', $code === 200 && count($log) <= 12, 'admin index query count constant (' . count($log) . ')');
DB::flushQueryLog();

// admin page show
DB::enableQueryLog();
[$code, $j] = http('GET', '/api/v1/content-pages/' . $n1Page, null, $GLOBALS['adminToken']);
$log = DB::getQueryLog();
ev('  admin page show (sections=' . $secCount . '): totalQueries=' . count($log));
record('TC-N1-004', $code === 200 && count($log) <= 12, 'admin show query count constant (' . count($log) . ')');
DB::flushQueryLog();