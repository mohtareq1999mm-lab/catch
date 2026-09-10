<?php

// =====================================================================
// PHASE 7 — SECTION TYPE CRUD
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 7 — SECTION TYPE CRUD');

// update the custom type
$ct = DB::table('section_types')->where('type', 'new-arrivals')->first();
[$code, $json] = http('PUT', '/api/v1/section-types/' . $ct->type, ['type' => 'latest-arrivals'], $adminToken);
ev('  update type new-arrivals -> latest-arrivals HTTP=' . $code);
$ctAfter = DB::table('section_types')->where('id', $ct->id)->first();
record('TC-ST-U-001', $code === 200 && $ctAfter->type === 'latest-arrivals', 'DB type=' . $ctAfter->type);

// duplicate type -> 422 + zero mutation
$before = DB::table('section_types')->count();
[$code] = http('POST', '/api/v1/section-types', ['type' => 'banners'], $adminToken);
$after = DB::table('section_types')->count();
record('TC-ST-V-001', $code === 422 && $before === $after, 'duplicate type HTTP=' . $code);

// unknown type -> 404 on settings GET
[$code] = http('GET', '/api/v1/section-types/does-not-exist/settings', [], $adminToken);
record('TC-ST-V-002', $code === 404, 'unknown type settings HTTP=' . $code);

// delete the custom type (no sections/settings) -> cascade trivially
[$code] = http('DELETE', '/api/v1/section-types/latest-arrivals', [], $adminToken);
$gone = DB::table('section_types')->where('id', $ct->id)->count() === 0;
record('TC-ST-D-001', $code === 200 && $gone, 'type delete HTTP=' . $code);

// type delete with settings + existing sections referencing the type string
[$code] = http('POST', '/api/v1/section-types', ['type' => 'testimonials'], $adminToken);
$tType = DB::table('section_types')->where('type', 'testimonials')->first();
http('POST', '/api/v1/section-types/testimonials/settings', ['front' => ['show' => true], 'back' => ['limit' => 3]], $adminToken);
[$sc] = http('POST', '/api/v1/sections', ['type' => 'testimonials', 'title' => ['en' => 'Customer Reviews', 'ar' => 'آراء العملاء'], 'is_active' => 1], $adminToken);
$sectRef = DB::table('sections')->orderByDesc('id')->value('id');
$settingsBefore = DB::table('section_type_settings')->where('section_type_id', $tType->id)->count();
ev('  testimonials type id=' . $tType->id . ' settings rows=' . $settingsBefore . ' section id=' . $sectRef);
[$code] = http('DELETE', '/api/v1/section-types/testimonials', [], $adminToken);
$settingsAfter = DB::table('section_type_settings')->where('section_type_id', $tType->id)->count();
$typeGone = DB::table('section_types')->where('id', $tType->id)->count() === 0;
$sectSurvives = DB::table('sections')->where('id', $sectRef)->count() === 1;
record('TC-ST-D-002', $code === 200 && $typeGone && $settingsAfter === 0 && $settingsBefore > 0, 'settings cascaded (before=' . $settingsBefore . ' after=' . $settingsAfter . ')');
record('TC-ST-D-003', $sectSurvives, 'section survives as orphan (no FK on sections.type)');

// =====================================================================
// PHASE 8 — SECTION TYPE SETTINGS (bulk delete / no stale rows)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 8 — SECTION TYPE SETTINGS');

[$code, $json] = http('POST', '/api/v1/section-types/products/settings', [
    'front' => ['title' => 'Best Selling Products', 'columns' => 4],
    'back' => ['limit' => 8, 'sort' => 'desc'],
], $adminToken);
$prodType = DB::table('section_types')->where('type', 'products')->first();
$rows = DB::table('section_type_settings')->where('section_type_id', $prodType->id)->orderBy('setting_key')->get();
ev('  HTTP=' . $code . ' settings rows: ' . json_encode($rows));
$frontVal = json_decode($rows->where('setting_key', 'front')->first()->value, true);
$backVal = json_decode($rows->where('setting_key', 'back')->first()->value, true);
$ok = $code === 200
    && $rows->count() === 2
    && $frontVal['columns'] === 4
    && $backVal['limit'] === 8;
record('TC-SS-001', $ok, 'front+back persisted with correct values/types');

// replace -> prove old records actually gone
[$code, $json] = http('POST', '/api/v1/section-types/products/settings', [
    'front' => ['title' => 'Best Selling Products', 'columns' => 6],
    'back' => ['limit' => 12],
], $adminToken);
$rows = DB::table('section_type_settings')->where('section_type_id', $prodType->id)->orderBy('setting_key')->get();
ev('  after replace: ' . json_encode($rows));
$noStale = $rows->count() === 2
    && json_decode($rows->where('setting_key', 'back')->first()->value, true)['limit'] === 12
    && !array_key_exists('sort', json_decode($rows->where('setting_key', 'back')->first()->value, true));
record('TC-SS-002', $code === 200 && $noStale, 'bulk-delete replaced settings; old back.sort gone, no duplicates');

// GET settings returns persisted values
[$code, $json] = http('GET', '/api/v1/section-types/products/settings', [], $adminToken);
record('TC-SS-003', $code === 200 && $json['data']['back']['limit'] === 12, 'GET settings returns persisted back.limit=12');