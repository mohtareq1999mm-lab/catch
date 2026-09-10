<?php

// =====================================================================
// PHASE 8 — SECTION CREATE (fields, auto-order, mass assignment)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 8 — SECTION CREATE');

$secBefore = DB::table('sections')->count();
$maxOrderBefore = (int) DB::table('sections')->max('order');

$specs = [
    ['type' => 'banners', 'title' => ['en' => 'Hero Banner', 'ar' => 'بانر رئيسي'], 'setting' => ['front' => ['heading' => 'Summer'], 'back' => []]],
    ['type' => 'categories', 'title' => ['en' => 'Top Categories', 'ar' => 'أفضل التصنيفات']],
    ['type' => 'products', 'title' => ['en' => 'New Arrivals', 'ar' => 'وصل حديثاً'], 'setting' => ['front' => [], 'back' => ['limit' => 5, 'type' => 'new_arrivals']]],
    ['type' => 'tags', 'title' => ['en' => 'Popular Tags', 'ar' => 'وسوم شائعة']],
    ['type' => 'brands', 'title' => ['en' => 'Top Brands', 'ar' => 'أفضل العلامات']],
    ['type' => 'sliders', 'title' => ['en' => 'Home Sliders', 'ar' => 'سلايدرز']],
];
$secIds = [];
foreach ($specs as $i => $spec) {
    $payload = ['type' => $spec['type'], 'title' => $spec['title'], 'is_active' => 1, 'title_visible' => 1];
    if (isset($spec['setting'])) {
        $payload['setting'] = $spec['setting'];
    }
    [$code, $sj] = http('POST', '/api/v1/sections', $payload, $GLOBALS['adminToken']);
    $secIds[] = $sj['data']['id'];
    ev('  section "' . $spec['title']['en'] . '" HTTP=' . $code . ' id=' . $sj['data']['id']);
}
$r = row('sections', $secIds[0]);
$t = json($r->title);
$autoOrder = (int) $r->order === $maxOrderBefore + 1;
record('TC-SC-001', $r !== null && $r->type === 'banners' && $t['en'] === 'Hero Banner' && $t['ar'] === 'بانر رئيسي'
    && (int) $r->is_active === 1 && (int) $r->title_visible === 1
    && $r->content_page_id === null && $autoOrder
    && json($r->setting) === ['front' => ['heading' => 'Summer'], 'back' => []], 'every persisted field verified; order auto-assigned=' . $r->order . ' (max+1=' . $autoOrder . ')');

// response<->DB for section
[$code, $sj] = http('GET', '/api/v1/sections/' . $secIds[0], null, $GLOBALS['adminToken']);
$dr = row('sections', $secIds[0]);
$respOk = $code === 200 && (int) $sj['data']['id'] === (int) $dr->id && $sj['data']['type'] === $dr->type
    && $sj['data']['title'] === 'Hero Banner' && $sj['data']['is_active'] === true && (int) $sj['data']['order'] === (int) $dr->order
    && $sj['data']['setting'] === json($dr->setting);
record('TC-SC-RESP', $respOk, 'admin GET section response == DB row (id/type/title/is_active/order/setting)');

// --- mass assignment attempt: extra fields must be IGNORED
[$code, $sj] = http('POST', '/api/v1/sections', [
    'type' => 'tags', 'title' => ['en' => 'Mass Attempt', 'ar' => 'محاولة'],
    'content_page_id' => 999, 'id' => 777, 'slug' => 'evil-slug', 'endpoint' => 'general/WRONG', 'created_at' => '2000-01-01 00:00:00',
], $GLOBALS['adminToken']);
$ma = row('sections', $sj['data']['id']);
$maOk = $ma->content_page_id === null && (int) $ma->id !== 777 && $ma->slug === null
    && (string) $ma->created_at !== '2000-01-01 00:00:00'
    && $ma->endpoint === 'general/tags';
ev('  mass-attempt DB row: ' . json_encode($ma));
record('TC-SC-MASS', $maOk, 'mass assignment blocked: content_page_id/id/slug/endpoint/created_at ignored (DB proven)');

// invalid creates -> zero mutation
$invalidSec = [
    'missing type' => ['title' => ['en' => 'No Type', 'ar' => 'بدون']],
    'invalid type' => ['type' => 'not-a-type', 'title' => ['en' => 'Bad', 'ar' => 'سيئ']],
    'missing title' => ['type' => 'banners'],
    'bad is_active' => ['type' => 'banners', 'title' => ['en' => 'X', 'ar' => 'إكس'], 'is_active' => 9],
    'bad title_visible' => ['type' => 'banners', 'title' => ['en' => 'X', 'ar' => 'إكس'], 'title_visible' => 'yes'],
    'oversized title' => ['type' => 'banners', 'title' => ['en' => str_repeat('z', 51), 'ar' => 'طويل']],
    'setting not array' => ['type' => 'banners', 'title' => ['en' => 'X', 'ar' => 'إكس'], 'setting' => 'string'],
];
foreach ($invalidSec as $label => $payload) {
    $snapBefore = snapJson($GLOBALS['tables']);
    [$code] = http('POST', '/api/v1/sections', $payload, $GLOBALS['adminToken']);
    record('TC-SC-INV-' . md5($label), $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'invalid section create (' . $label . ') -> HTTP=' . $code . ' zero mutation');
}

// =====================================================================
// PHASE 9 — ATTACH / DETACH
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 9 — ATTACH / DETACH');

[$code, $pj] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Home', 'ar' => 'الرئيسية']], $GLOBALS['adminToken']);
$homePage = $pj['data']['id'];

$orphan = [];
foreach ([['en' => 'Sec A', 'ar' => 'أ'], ['en' => 'Sec B', 'ar' => 'ب'], ['en' => 'Sec C', 'ar' => 'ج']] as $t) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => $t, 'is_active' => 1], $GLOBALS['adminToken']);
    $orphan[] = $sj['data']['id'];
}
[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => $orphan], $GLOBALS['adminToken']);
$mapped = DB::table('sections')->whereIn('id', $orphan)->pluck('content_page_id')->all();
record('TC-AT-001', $code === 200 && $mapped === [$homePage, $homePage, $homePage], 'attach: DB content_page_id set to page for all 3 (' . json_encode($mapped) . ')');

// re-attach subset -> additive (only intended change: none detached)
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [$orphan[0], $orphan[1]]], $GLOBALS['adminToken']);
$mapped2 = DB::table('sections')->whereIn('id', $orphan)->pluck('content_page_id')->all();
$unrelated = DB::table('sections')->where('content_page_id', $homePage)->whereNotIn('id', $orphan)->count();
record('TC-AT-002', $code === 200 && $mapped2 === [$homePage, $homePage, $homePage], 'subset re-attach additive: no detach semantics, mapping unchanged (' . json_encode($mapped2) . ')');

// detach all via [] -> NULL for exactly the 3
[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => []], $GLOBALS['adminToken']);
$detached = DB::table('sections')->whereIn('id', $orphan)->whereNull('content_page_id')->count();
record('TC-AT-003', $code === 200 && $detached === 3, 'detach-all: exactly 3 sections content_page_id=NULL');

// invalid ids -> 422 zero mutation
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('POST', '/api/v1/content-pages/' . $homePage . '/attach-sections', ['sections' => [999999]], $GLOBALS['adminToken']);
record('TC-AT-004', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'invalid section id -> HTTP=' . $code . ' zero mutation');

// =====================================================================
// PHASE 10 — REORDER EXTREME (+ PUT 405) + INVALID
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 10 — REORDER');

[$code, $pj] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Reorder Home', 'ar' => 'الترتيب']], $GLOBALS['adminToken']);
$roPage = $pj['data']['id'];
$ro = [];
foreach ([['en' => 'R1', 'ar' => '1'], ['en' => 'R2', 'ar' => '2'], ['en' => 'R3', 'ar' => '3'], ['en' => 'R4', 'ar' => '4'], ['en' => 'R5', 'ar' => '5'], ['en' => 'R6', 'ar' => '6']] as $t) {
    [$sc, $sj] = http('POST', '/api/v1/sections', ['type' => 'tags', 'title' => $t, 'is_active' => 1], $GLOBALS['adminToken']);
    $ro[] = $sj['data']['id'];
}
http('POST', '/api/v1/content-pages/' . $roPage . '/attach-sections', ['sections' => $ro], $GLOBALS['adminToken']);
$beforeOrder = DB::table('sections')->whereIn('id', $ro)->orderBy('order')->pluck('order', 'id')->all();
ev('  BEFORE: ' . json_encode($beforeOrder));

$want = [$ro[4], $ro[0], $ro[3], $ro[1], $ro[5], $ro[2]]; // [R5,R1,R4,R2,R6,R3]
[$code] = http('POST', '/api/v1/sections/reorder', ['sections' => $want], $GLOBALS['adminToken']);
$after = DB::table('sections')->whereIn('id', $ro)->orderBy('order')->get(['id', 'order']);
ev('  AFTER: ' . json_encode($after));
$orderMap = [];
foreach ($after as $o) {
    $orderMap[$o->id] = (int) $o->order;
}
$expect = [];
foreach ($want as $i => $sid) {
    $expect[$sid] = $i + 1;
}
record('TC-RO-001', $code === 200 && $orderMap == $expect, 'DB order == exact requested mapping');

// public API order == DB order
[$code, $j] = http('GET', '/api/v1/general/content-pages/reorder-home');
$pubOrder = array_column($j['data']['sections'] ?? [], 'id');
$dbOrder = DB::table('sections')->where('content_page_id', $roPage)->orderBy('order')->pluck('id')->all();
record('TC-RO-002', $code === 200 && $pubOrder === $dbOrder && $pubOrder === $want, 'public order equals DB order (' . json_encode($pubOrder) . ')');

// PUT is rejected per approved contract (only POST registered)
[$code] = http('PUT', '/api/v1/sections/reorder', ['sections' => $want], $GLOBALS['adminToken']);
$orderUnchanged = DB::table('sections')->whereIn('id', $ro)->orderBy('id')->pluck('order', 'id')->all() === $beforeOrder;
record('TC-RO-PUT', $code === 405 && $orderUnchanged, 'PUT /sections/reorder -> HTTP=' . $code . ' (405, contract only allows POST); order unchanged');

// invalid reorder payloads -> 422 zero mutation
$invalid = [
    'empty' => ['sections' => []],
    'duplicate' => ['sections' => [$ro[0], $ro[0], $ro[1]]],
    'unknown' => ['sections' => [999999, $ro[0]]],
    'string' => ['sections' => ['abc', $ro[0]]],
    'float' => ['sections' => [1.5, $ro[0]]],
    'null' => ['sections' => [null, $ro[0]]],
    'missing' => [],
    'wrong-type' => ['sections' => 'nope'],
];
$allOk = true;
foreach ($invalid as $label => $payload) {
    $before = DB::table('sections')->whereIn('id', $ro)->orderBy('id')->pluck('order', 'id')->all();
    [$code] = http('POST', '/api/v1/sections/reorder', $payload, $GLOBALS['adminToken']);
    $after2 = DB::table('sections')->whereIn('id', $ro)->orderBy('id')->pluck('order', 'id')->all();
    $ok = $code === 422 && $before === $after2;
    if (!$ok) {
        $allOk = false;
    }
    ev('  invalid reorder (' . $label . '): HTTP=' . $code . ' orderUnchanged=' . ($before === $after2 ? 'yes' : 'NO'));
}
record('TC-RO-003', $allOk, '8 invalid reorder payloads -> 422 + ZERO order mutation');

// =====================================================================
// PHASE 11 — SECTION UPDATE / TOGGLE / DELETE
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 11 — SECTION UPDATE / TOGGLE / DELETE');

$uSec = $secIds[1]; // Top Categories
$b = row('sections', $uSec);
ev('  BEFORE: ' . json_encode($b));

// per-field: title.en only
[$code] = http('PUT', '/api/v1/sections/' . $uSec, ['title' => ['en' => 'Top Categories Updated']], $GLOBALS['adminToken']);
$a = row('sections', $uSec);
$t = json($a->title);
record('TC-SU-001', $code === 200 && $t['en'] === 'Top Categories Updated' && $t['ar'] === 'أفضل التصنيفات'
    && (int) $a->is_active === (int) $b->is_active && $a->type === $b->type && $a->content_page_id === $b->content_page_id
    && (string) $a->created_at === (string) $b->created_at && $a->updated_at !== null, 'title.en update; type/content_page_id/created_at unchanged');

// per-field: is_active only
[$code] = http('PUT', '/api/v1/sections/' . $uSec, ['is_active' => 0], $GLOBALS['adminToken']);
$a2 = row('sections', $uSec);
record('TC-SU-002', $code === 200 && (int) $a2->is_active === 0 && json($a2->title) === json($a->title), 'is_active update only; title untouched');

// per-field: title_visible + setting
[$code] = http('PUT', '/api/v1/sections/' . $uSec, ['title_visible' => 0, 'setting' => ['front' => ['badge' => 'HOT'], 'back' => []]], $GLOBALS['adminToken']);
$a3 = row('sections', $uSec);
record('TC-SU-003', $code === 200 && (int) $a3->title_visible === 0 && json($a3->setting) === ['front' => ['badge' => 'HOT'], 'back' => []], 'title_visible + setting persisted (DB proven)');

// response<->DB after update
[$code, $sj] = http('GET', '/api/v1/sections/' . $uSec, null, $GLOBALS['adminToken']);
$dr = row('sections', $uSec);
record('TC-SU-RESP', $code === 200 && (int) $sj['data']['id'] === (int) $dr->id && $sj['data']['type'] === $dr->type && (int) $sj['data']['order'] === (int) $dr->order && $sj['data']['setting'] === json($dr->setting), 'update response == DB');

// restore for later
http('PUT', '/api/v1/sections/' . $uSec, ['is_active' => 1, 'title_visible' => 1], $GLOBALS['adminToken']);

// TOGGLE 1->0->1
[$code, $sj] = http('PATCH', '/api/v1/sections/' . $uSec . '/toggle-active', [], $GLOBALS['adminToken']);
$db0 = (int) DB::table('sections')->where('id', $uSec)->value('is_active');
[$code2, $sj] = http('PATCH', '/api/v1/sections/' . $uSec . '/toggle-active', [], $GLOBALS['adminToken']);
$db1 = (int) DB::table('sections')->where('id', $uSec)->value('is_active');
record('TC-TG-001', $code === 200 && $db0 === 0, 'toggle 1->0: DB is_active=0');
record('TC-TG-002', $code2 === 200 && $db1 === 1, 'toggle 0->1: DB is_active=1');

// DELETE
$victim = $secIds[5];
[$code] = http('DELETE', '/api/v1/sections/' . $victim, [], $GLOBALS['adminToken']);
$gone = row('sections', $victim) === null;
record('TC-SD-001', $code === 200 && $gone, 'section hard-deleted (SELECT=0)');

// invalid section update -> zero mutation
$snapBefore = snapJson($GLOBALS['tables']);
[$code] = http('PUT', '/api/v1/sections/' . $uSec, ['order' => 'not-int', 'type' => 'banners'], $GLOBALS['adminToken']);
record('TC-SU-INV', $code === 422 && snapJson($GLOBALS['tables']) === $snapBefore, 'invalid section update (order string + type) -> HTTP=' . $code . ' zero mutation');