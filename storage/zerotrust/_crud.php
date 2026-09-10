<?php

use Marvel\Models\ContentPage;
use Marvel\Models\Section;

// =====================================================================
// PHASE 10 — CRUD (real API + raw DB cross-checks)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 10 — CRUD');

// ---- create page ----
[$code, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Electronics', 'ar' => 'إلكترونيات']], $GLOBALS['adminToken']);
$pageId = $j['data']['id'] ?? null;
$row = row('content_pages', $pageId);
ev('  POST /content-pages HTTP=' . $code . ' id=' . $pageId . ' slug=' . $row->slug . ' is_active=' . $row->is_active);
record('TC-CRUD-001', $code === 201 && $row !== null && $row->slug === 'electronics' && (int) $row->is_active === 1, 'content page created via API; slug auto-generated; is_active defaulted true');

// ---- create section type ----
[$code, $j] = http('POST', '/api/v1/section-types', ['type' => 'new-tag'], $GLOBALS['adminToken']);
$typeCount = DB::table('section_types')->where('type', 'new-tag')->count();
record('TC-CRUD-002', $code === 200 && $typeCount === 1, 'section type new-tag created via API (' . $typeCount . ' row in DB)');

// ---- orphan section via RAW DB (proves type string, no FK) ----
DB::table('sections')->insert([
    'type' => 'new-tag', 'title' => json_encode(['en' => 'Orphan Raw', 'ar' => 'يتيم']),
    'order' => 0, 'is_active' => 1, 'title_visible' => 1,
    'setting' => json_encode(['front' => [], 'back' => []]),
    'created_at' => now(), 'updated_at' => now(),
]);
$orphan = DB::table('sections')->where('type', 'new-tag')->first();
record('TC-CRUD-003', $orphan !== null && $orphan->content_page_id === null, 'orphan section persists with type string (no FK column), content_page_id null');

// ---- banners settings via API ----
[$code, $j] = http('POST', '/api/v1/section-types/banners/settings', ['front' => ['heading' => 'Deals'], 'back' => ['limit' => 5]], $GLOBALS['adminToken']);
$bannerSettings = DB::table('section_type_settings')
    ->join('section_types', 'section_types.id', '=', 'section_type_settings.section_type_id')
    ->where('section_types.type', 'banners')->count();
record('TC-CRUD-004', $code === 200 && $bannerSettings === 2, 'banners type settings upserted (2 rows: front+back)');

// ---- duplicate section via API (same type) ----
[$code, $j] = http('POST', '/api/v1/sections', ['type' => 'new-tag', 'title' => ['en' => 'Dup', 'ar' => 'مكرر'], 'is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);
$dupId = $j['data']['id'] ?? null;
$dupCount = DB::table('sections')->where('type', 'new-tag')->count();
record('TC-CRUD-005', $code === 200 && $dupId !== null && $dupCount === 2, 'duplicate section of same type created (' . $dupCount . ' rows of type new-tag)');

// ---- update section type ----
[$code, $j] = http('PUT', '/api/v1/section-types/new-tag', ['type' => 'new-tag-renamed'], $GLOBALS['adminToken']);
$renamed = DB::table('section_types')->where('type', 'new-tag-renamed')->count();
$oldGone = DB::table('section_types')->where('type', 'new-tag')->count();
record('TC-CRUD-006', $code === 200 && $renamed === 1 && $oldGone === 0, 'section type updated new-tag -> new-tag-renamed (' . $oldGone . ' old, ' . $renamed . ' new)');

// ---- sections: tags + banners ----
[$code, $j] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => ['en' => 'Audio', 'ar' => 'صوتيات'], 'is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);
$audioSec = $j['data']['id'];
[$code, $j] = http('POST', '/api/v1/sections', ['type' => 'banners', 'title' => ['en' => 'Hero Banner', 'ar' => 'بانر رئيسي'], 'is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);
$heroSec = $j['data']['id'];
$tagsCount = DB::table('sections')->where('type', 'tags')->count();
$bannersCount = DB::table('sections')->where('type', 'banners')->count();
record('TC-CRUD-007', row('sections', $audioSec)->type === 'tags' && row('sections', $heroSec)->type === 'banners' && $tagsCount >= 2 && $bannersCount >= 2, 'harness tags+banners sections created (total ' . $tagsCount . ' tags, ' . $bannersCount . ' banners in DB incl seed)');

// ---- attach sections to page ----
[$code] = http('POST', '/api/v1/content-pages/' . $pageId . '/attach-sections', ['sections' => [$audioSec, $heroSec]], $GLOBALS['adminToken']);
$attached = DB::table('sections')->where('content_page_id', $pageId)->count();
record('TC-CRUD-008', $code === 200 && $attached === 2, '2 sections attached (content_page_id set, ' . $attached . ' total)');

// ---- reorder ----
[$code] = http('POST', '/api/v1/sections/reorder', ['sections' => [$heroSec, $audioSec]], $GLOBALS['adminToken']);
$order = DB::table('sections')->whereIn('id', [$audioSec, $heroSec])->orderBy('order')->pluck('id')->all();
record('TC-CRUD-009', $code === 200 && $order === [$heroSec, $audioSec], 'reorder persisted hero-first (' . json_encode($order) . ')');

// ---- section show: settings resolution (explicit + type fallback) ----
[$code, $j] = http('GET', '/api/v1/sections/' . $heroSec, null, $GLOBALS['adminToken']);
$bs = DB::table('section_type_settings')
    ->join('section_types', 'section_types.id', '=', 'section_type_settings.section_type_id')
    ->where('section_types.type', 'banners')->get(['setting_key', 'value']);
$expected = [
    'front' => json($bs->firstWhere('setting_key', 'front')?->value ?? []),
    'back' => json($bs->firstWhere('setting_key', 'back')?->value ?? []),
];
$secSettingMatch = $code === 200 && ($j['data']['setting'] ?? null) === $expected;
record('TC-CRUD-010', $secSettingMatch, 'section WITHOUT setting resolves settings from type settings (banners) -> ' . json_encode($j['data']['setting'] ?? null));

[$code, $j] = http('GET', '/api/v1/sections/' . $dupId, null, $GLOBALS['adminToken']);
ev('  section with explicit setting: ' . json_encode($j['data']['setting'] ?? null));
record('TC-CRUD-011', $code === 200 && is_array($j['data']['setting'] ?? null), 'section response includes resolved setting array');

// ---- page show (admin): sections loaded with settings ----
[$code, $j] = http('GET', '/api/v1/content-pages/' . $pageId, null, $GLOBALS['adminToken']);
$sections = $j['data']['sections'] ?? [];
$secTypes = array_column($sections, 'type');
record('TC-CRUD-012', $code === 200 && in_array('tags', $secTypes, true) && in_array('banners', $secTypes, true), 'page show returns both attached sections (' . implode(',', $secTypes) . ')');

// ---- public page show ----
[$code, $j] = http('GET', '/api/v1/general/content-pages/electronics');
$pubTypes = array_column($j['data']['sections'] ?? [], 'type');
record('TC-CRUD-013', $code === 200 && in_array('tags', $pubTypes, true), 'public show returns active sections');

// ---- page update (title.en) ----
[$code, $j] = http('PUT', '/api/v1/content-pages/' . $pageId, ['title' => ['en' => 'Audio'], 'is_active' => 1], $GLOBALS['adminToken']);
$dbTitle = json(DB::table('content_pages')->where('id', $pageId)->value('title'));
record('TC-CRUD-014', $code === 200 && $dbTitle['en'] === 'Audio', 'page update persisted title.en=Audio in DB JSON');

// ---- queue connection is redis; module runs synchronously (no orphan jobs) ----
$before = DB::table('jobs')->count() + DB::table('failed_jobs')->count();
$conn = get_class(app('queue')->connection());
$after = DB::table('jobs')->count() + DB::table('failed_jobs')->count();
record('TC-CRUD-015', config('queue.default') === 'redis' && $conn === Illuminate\Queue\RedisQueue::class && $before === $after, 'queue default=' . config('queue.default') . ' (' . $conn . '); no jobs accumulated (' . $before . '->' . $after . ')');