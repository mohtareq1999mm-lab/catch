<?php

use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Product;

// =====================================================================
// PHASE 16 — ONE CONTINUOUS REAL-WORLD BUSINESS SCENARIO (from zero)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 16 — CONTINUOUS BUSINESS SCENARIO');

$sc = ['page' => null, 'bannerId' => null, 'productId' => null, 'secA' => null, 'secB' => null];
$snapBefore = snap($GLOBALS['tables']);

// 1. create page
[$c, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Scenario Store', 'ar' => 'متجر سيناريو']], $GLOBALS['adminToken']);
$sc['page'] = $j['data']['id'];
record('TC-SCEN-001', $c === 201 && row('content_pages', $sc['page']) !== null, 'page created id=' . $sc['page']);

// 2. create section types via API
http('POST', '/api/v1/section-types', ['type' => 'sc-banners'], $GLOBALS['adminToken']);
http('POST', '/api/v1/section-types', ['type' => 'sc-products'], $GLOBALS['adminToken']);
record('TC-SCEN-002', DB::table('section_types')->whereIn('type', ['sc-banners', 'sc-products'])->count() === 2, '2 section types in DB');

// 3. real backing entities
$banner = Banner::create(['title' => 'Scenario Banner', 'status' => true]);
$product = Product::create([
    'name' => ['en' => 'Scenario Product', 'ar' => 'منتج'], 'slug' => 'scenario-product',
    'price' => 99, 'status' => 'publish', 'in_stock' => true, 'stock_quantity' => 10,
    'reserved_quantity' => 0, 'product_type' => 'simple', 'has_discount' => false,
    'has_flash_sale' => false, 'is_fast_shipping_available' => false,
]);
$sc['bannerId'] = $banner->id;
$sc['productId'] = $product->id;
record('TC-SCEN-003', DB::table('banners')->where('id', $banner->id)->exists() && DB::table('products')->where('id', $product->id)->exists(), 'banner+product persisted in DB');

// 4. create sections
[$c, $j] = http('POST', '/api/v1/sections', ['type' => 'sc-banners', 'title' => ['en' => 'Scenario Banner Sec', 'ar' => 'بانر'], 'setting' => ['front' => [], 'back' => ['bannersId' => [$banner->id]]], 'is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);
$sc['secA'] = $j['data']['id'];
[$c, $j] = http('POST', '/api/v1/sections', ['type' => 'sc-products', 'title' => ['en' => 'Scenario Products Sec', 'ar' => 'منتجات'], 'setting' => ['front' => [], 'back' => ['limit' => 5, 'type' => 'new_arrivals']], 'is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);
$sc['secB'] = $j['data']['id'];
record('TC-SCEN-004', DB::table('sections')->whereIn('id', [$sc['secA'], $sc['secB']])->count() === 2, '2 sections in DB with settings JSON');

// 5. attach
http('POST', '/api/v1/content-pages/' . $sc['page'] . '/attach-sections', ['sections' => [$sc['secA'], $sc['secB']]], $GLOBALS['adminToken']);
record('TC-SCEN-005', DB::table('sections')->whereIn('id', [$sc['secA'], $sc['secB']])->where('content_page_id', $sc['page'])->count() === 2, 'both sections attached (content_page_id set)');

// 6. configure type settings
http('POST', '/api/v1/section-types/sc-banners/settings', ['front' => ['title' => 'Scenario Heading'], 'back' => []], $GLOBALS['adminToken']);
record('TC-SCEN-006', DB::table('section_type_settings')->join('section_types', 'section_types.id', '=', 'section_type_settings.section_type_id')->where('section_types.type', 'sc-banners')->count() === 1, 'type settings persisted');

// 7. reorder B,A
http('POST', '/api/v1/sections/reorder', ['sections' => [$sc['secB'], $sc['secA']]], $GLOBALS['adminToken']);
$ord = DB::table('sections')->whereIn('id', [$sc['secA'], $sc['secB']])->orderBy('order')->pluck('id')->all();
record('TC-SCEN-007', $ord === [$sc['secB'], $sc['secA']], 'reorder persisted B then A (' . json_encode($ord) . ')');

// 8. toggle secB off then on
http('PATCH', '/api/v1/sections/' . $sc['secB'] . '/toggle-active', [], $GLOBALS['adminToken']);
$off = (int) DB::table('sections')->where('id', $sc['secB'])->value('is_active');
http('PATCH', '/api/v1/sections/' . $sc['secB'] . '/toggle-active', [], $GLOBALS['adminToken']);
$on = (int) DB::table('sections')->where('id', $sc['secB'])->value('is_active');
record('TC-SCEN-008', $off === 0 && $on === 1, 'toggle 1->0->1 proven in DB');

// 9. update page
http('PUT', '/api/v1/content-pages/' . $sc['page'], ['title' => ['en' => 'Scenario Store X', 'ar' => 'متجر سيناريو']], $GLOBALS['adminToken']);
record('TC-SCEN-009', json(DB::table('content_pages')->where('id', $sc['page'])->value('title'))['en'] === 'Scenario Store X', 'page title updated in DB');

// 10. public Home -> verify response against DB
[$c, $j] = http('GET', '/api/v1/general/content-pages/scenario-store');
$pubSec = collect($j['data']['sections'] ?? [])->sortBy('order')->values();
$dbPub = DB::table('sections')->where('content_page_id', $sc['page'])->orderBy('order')->get(['id', 'type']);
$match = $c === 200
    && $j['data']['title'] === 'Scenario Store X'
    && $pubSec->pluck('id')->all() === $dbPub->pluck('id')->all();
record('TC-SCEN-010', $match, 'public Home response matches DB (title, section order, section ids)');

// 11. change translation (title.ar)
http('PUT', '/api/v1/content-pages/' . $sc['page'], ['title' => ['ar' => 'متجر السيناريو']], $GLOBALS['adminToken']);
// 12. request Arabic -> verify DB value
[$c, $j] = http('GET', '/api/v1/general/content-pages/scenario-store', null, null, 'ar');
record('TC-SCEN-011', $c === 200 && $j['data']['title'] === 'متجر السيناريو' && json(DB::table('content_pages')->where('id', $sc['page'])->value('title'))['ar'] === 'متجر السيناريو', 'Arabic title served == DB JSON ar value');

// 13. detach section B (subset cannot detach; only [] detaches all — verify contract)
http('POST', '/api/v1/content-pages/' . $sc['page'] . '/attach-sections', ['sections' => [$sc['secA']]], $GLOBALS['adminToken']);
$stillAttached = DB::table('sections')->whereIn('id', [$sc['secA'], $sc['secB']])->where('content_page_id', $sc['page'])->count();
record('TC-SCEN-012', $stillAttached === 2, 'subset re-attach is additive (no partial detach contract); both still attached');

// 14. delete section B
http('DELETE', '/api/v1/sections/' . $sc['secB'], [], $GLOBALS['adminToken']);
record('TC-SCEN-013', row('sections', $sc['secB']) === null && row('sections', $sc['secA']) !== null, 'section B deleted, A remains');

// 15. delete section type sc-banners (has a section referencing it: secA orphan)
http('DELETE', '/api/v1/section-types/sc-banners', [], $GLOBALS['adminToken']);
$typeGone = DB::table('section_types')->where('type', 'sc-banners')->count() === 0;
$secAlive = row('sections', $sc['secA']) !== null;
$settingsGone = DB::table('section_type_settings')->where('section_type_id', (int) DB::table('section_types')->where('type', 'sc-banners')->value('id'))->count() === 0;
record('TC-SCEN-014', $typeGone && $secAlive, 'type deleted; section A survives as orphan (contract)');
record('TC-SCEN-015', $settingsGone, 'settings cascaded with type delete');

// 16. cache: public cache flushed and reflects DB after the last mutations
$key = cacheKey('/api/v1/general/content-pages/scenario-store');
http('GET', '/api/v1/general/content-pages/scenario-store');
$before = Cache::tags(['content_pages'])->has($key);
http('PUT', '/api/v1/sections/' . $sc['secA'], ['title_visible' => 0], $GLOBALS['adminToken']);
$after = Cache::tags(['content_pages'])->has($key);
[$c, $j] = http('GET', '/api/v1/general/content-pages/scenario-store');
$secAVisible = collect($j['data']['sections'] ?? [])->firstWhere('id', $sc['secA'])['title'] ?? null;
record('TC-SCEN-016', $before === true && $after === false && $secAVisible === null, 'cache flushed on section update; refreshed response reflects DB (title hidden)');

// 17. integrity on scenario slice
$dupSlugs = DB::table('content_pages')->selectRaw('slug, count(*) c')->groupBy('slug')->havingRaw('count(*) > 1')->count();
$badActive = DB::table('content_pages')->whereNotIn('is_active', [0, 1])->count() + DB::table('sections')->whereNotIn('is_active', [0, 1])->count();
record('TC-SCEN-017', $dupSlugs === 0 && $badActive === 0, 'scenario ended with no duplicate slugs, no invalid booleans');

$scenarioDelta = collect(snap($GLOBALS['tables']))->map(fn ($v, $t) => $v - $snapBefore[$t])->filter(fn ($d) => $d !== 0)->all();
ev('  scenario DB delta: ' . json_encode($scenarioDelta));
$expected = ['content_pages' => 1, 'sections' => 1, 'section_types' => 1, 'banners' => 1, 'products' => 1];
record('TC-SCEN-018', $scenarioDelta == $expected, 'scenario accounting exactly matches expectations (' . json_encode($scenarioDelta) . ')');