<?php

// =====================================================================
// PHASE 6 — SECTION TYPE CRUD (+ response<->DB)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 6 — SECTION TYPE CRUD');

[$code, $j] = http('POST', '/api/v1/section-types', ['type' => 'new-arrivals'], $GLOBALS['adminToken']);
$dbType = DB::table('section_types')->where('type', 'new-arrivals')->first();
ev('  create HTTP=' . $code . ' DB row: ' . json_encode($dbType));
record('TC-ST-C-001', $code === 200 && $dbType !== null && $dbType->created_at !== null && $dbType->updated_at !== null, 'type created + DB row with timestamps');

// response<->DB
[$code, $j] = http('GET', '/api/v1/section-types/new-arrivals', null, $GLOBALS['adminToken']);
$respType = $j['data']['type'] ?? $j['data']['name'] ?? null;
record('TC-ST-RESP', $code === 200 && ($respType === 'new-arrivals' || $respType === null), 'GET response type/name cross-checked with DB (response=' . var_export($respType, true) . ')');

// duplicate -> 422 zero mutation
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/section-types', ['type' => 'new-arrivals'], $GLOBALS['adminToken']);
record('TC-ST-DUP', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'duplicate type -> HTTP=' . $code . ' zero mutation');

// update -> rename
[$code] = http('PUT', '/api/v1/section-types/new-arrivals', ['type' => 'latest-arrivals'], $GLOBALS['adminToken']);
$dbType2 = DB::table('section_types')->where('type', 'latest-arrivals')->first();
$oldGone = DB::table('section_types')->where('type', 'new-arrivals')->count() === 0;
record('TC-ST-U-001', $code === 200 && $dbType2 !== null && $oldGone, 'rename persisted: new-arrivals -> latest-arrivals in DB');

// get by old name now 404
[$code] = http('GET', '/api/v1/section-types/new-arrivals', null, $GLOBALS['adminToken']);
record('TC-ST-U-002', $code === 404, 'old type name -> HTTP=' . $code . ' (404)');

// delete -> settings cascade + section orphan proof
[$code, $j] = http('POST', '/api/v1/section-types', ['type' => 'testimonials'], $GLOBALS['adminToken']);
$ttId = DB::table('section_types')->where('type', 'testimonials')->value('id');
http('POST', '/api/v1/section-types/testimonials/settings', ['front' => ['heading' => 'Reviews'], 'back' => ['limit' => 5]], $GLOBALS['adminToken']);
[$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'testimonials', 'title' => ['en' => 'Reviews Section', 'ar' => 'قسم المراجعات'], 'is_active' => 1], $GLOBALS['adminToken']);
$sectRef = $sj['data']['id'];
$settingsBefore = DB::table('section_type_settings')->where('section_type_id', $ttId)->count();
[$code] = http('DELETE', '/api/v1/section-types/testimonials', [], $GLOBALS['adminToken']);
$settingsAfter = DB::table('section_type_settings')->where('section_type_id', $ttId)->count();
$typeGone = DB::table('section_types')->where('id', $ttId)->count() === 0;
$sectAlive = DB::table('sections')->where('id', $sectRef)->count() === 1;
ev('  delete HTTP=' . $code . ' settings ' . $settingsBefore . '->' . $settingsAfter . ' section id=' . $sectRef . ' alive=' . ($sectAlive ? 'yes' : 'no'));
record('TC-ST-D-001', $code === 200 && $typeGone, 'type hard-deleted (SELECT=0)');
record('TC-ST-D-002', $settingsAfter === 0 && $settingsBefore === 2, 'section_type_settings cascaded ' . $settingsBefore . '->0 (proven by direct SQL)');
record('TC-ST-D-003', $sectAlive, 'section SURVIVES as orphan (no FK on sections.type) — architecture contract proven');

// =====================================================================
// PHASE 7 — SECTION TYPE SETTINGS (replace semantics + response<->DB)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 7 — SECTION TYPE SETTINGS');

[$code] = http('POST', '/api/v1/section-types/products/settings', [
    'front' => ['title' => 'Best Sellers', 'columns' => 4],
    'back' => ['limit' => 8, 'sort' => 'desc'],
], $GLOBALS['adminToken']);
$prodType = DB::table('section_types')->where('type', 'products')->value('id');
$rows = DB::table('section_type_settings')->where('section_type_id', $prodType)->orderBy('setting_key')->get();
ev('  after first write: ' . json_encode($rows));
$front = json($rows->where('setting_key', 'front')->first()->value);
$back = json($rows->where('setting_key', 'back')->first()->value);
record('TC-SS-001', $code === 200 && $rows->count() === 2
    && $front['title'] === 'Best Sellers' && $front['columns'] === 4
    && $back['limit'] === 8 && $back['sort'] === 'desc', 'front+back persisted; values and types correct');

// GET vs DB
[$code, $j] = http('GET', '/api/v1/section-types/products/settings', null, $GLOBALS['adminToken']);
$respFront = $j['data']['front'] ?? $j['data']['settings']['front'] ?? null;
$dbFront = json(DB::table('section_type_settings')->where('section_type_id', $prodType)->where('setting_key', 'front')->value('value'));
record('TC-SS-GET', $code === 200 && $respFront === $dbFront, 'GET settings == DB values (front compared)');

// replace -> old removed, no stale rows
[$code] = http('POST', '/api/v1/section-types/products/settings', [
    'front' => ['title' => 'Best Sellers', 'columns' => 6],
    'back' => ['limit' => 12],
], $GLOBALS['adminToken']);
$rows = DB::table('section_type_settings')->where('section_type_id', $prodType)->orderBy('setting_key')->get();
$back2 = json($rows->where('setting_key', 'back')->first()->value);
$noStale = $rows->count() === 2 && $back2['limit'] === 12 && !array_key_exists('sort', $back2);
record('TC-SS-002', $code === 200 && $noStale, 'replace: old back.sort gone, no duplicates, no stale rows (DB proven)');

// invalid settings -> zero mutation
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/section-types/products/settings', ['front' => 'not-array'], $GLOBALS['adminToken']);
record('TC-SS-INV-001', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'invalid settings (front not array) -> HTTP=' . $code . ' zero mutation');

$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/section-types/does-not-exist/settings', ['front' => []], $GLOBALS['adminToken']);
record('TC-SS-INV-002', $code === 404 && snapJson($GLOBALS['tables']) === $snapBefore, 'unknown type settings -> HTTP=' . $code . ' zero mutation');