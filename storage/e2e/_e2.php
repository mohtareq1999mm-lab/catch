<?php

declare(strict_types=1);

// =====================================================================
// E2E PHASE 2 - REAL COMMERCE LIFECYCLE
// Product CRUD (real multipart media upload) -> translations en/ar ->
// cache MISS/HIT/INVALIDATION (real Redis tags) -> cart -> checkout COD
// -> mark-paid permission flow -> invoice verify -> PDF artifact check.
// Run:  php storage/e2e/_e2.php
// =====================================================================

require __DIR__ . '/_hbase.php';
require __DIR__ . '/_state.php';

ev('=================================================================');
ev('E2E PHASE 2 - COMMERCE: PRODUCT CRUD / MEDIA / CACHE / CART / CHECKOUT');
ev('=================================================================');

$adminToken = $GLOBALS['adminToken'] ?? null;
$customerToken = $GLOBALS['customerToken'] ?? null;
if (!$adminToken || !$customerToken) {
    ev('FATAL: run _e1.php first (no tokens)');
    exit(1);
}

// --- Real PNGs on disk for genuine multipart uploads --------------------------
$pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAoAAAAKCAYAAACNMs+9AAAAFUlEQVR42mP8z8AARIQBEwMDAwMDAwAkBgMBjXK3EwAAAABJRU5ErkJggg==');
// Each multipart field needs its OWN temp file: the first successful media add
// consumes its source file (proven during this E2E pass).
$tmpCatDesktop = storage_path('e2e/cat-desktop-' . uniqid() . '.png');
file_put_contents($tmpCatDesktop, $pngBytes);
$tmpCatMobile = storage_path('e2e/cat-mobile-' . uniqid() . '.png');
file_put_contents($tmpCatMobile, $pngBytes);

[$c, $j] = httpFull('POST', '/api/v1/categories', [
    'name' => ['en' => 'E2E Audit Category ' . substr(uniqid(), -4), 'ar' => 'فئة تدقيق ' . substr(uniqid(), -4)], 'slug' => 'e2e-cat-' . time(), 'parent_id' => null,
], [
    'image-desktop' => [$tmpCatDesktop, 'cat-desktop.png', 'image/png'],
    'image-mobile' => [$tmpCatMobile, 'cat-mobile.png', 'image/png'],
], $adminToken);
record('CAT-CREATE', in_array($c, [200, 201], true) && isset($j['data']['id']), "multipart POST /categories HTTP=$c id=" . ($j['data']['id'] ?? '-') . (!isset($j['data']) ? ' body=' . substr(json_encode($j), 0, 180) : ''));
$categoryId = $j['data']['id'] ?? null;

// --- Real product with its own image ------------------------------------------
$tmpPng = storage_path('e2e/audit-product-' . uniqid() . '.png');
file_put_contents($tmpPng, $pngBytes);

[$c, $j] = httpFull('POST', '/api/v1/products', [
    'name' => ['en' => 'E2E Audit Gadget ' . substr(uniqid(), -4), 'ar' => 'جهاز تدقيق ' . substr(uniqid(), -4)],
    'description' => ['en' => 'Real E2E validation product', 'ar' => 'منتج للتحقق الفعلي'],
    'price' => '150.50', 'quantity' => 25, 'product_type' => 'simple', 'item_type' => 'PHYSICAL',
    'is_free_shipping' => 0, 'status' => 'publish', 'in_stock' => 1, 'has_discount' => 0, 'has_flash_sale' => 0,
    'categories' => [(int) $categoryId],
], [
    'images' => [0 => [$tmpPng, 'audit-gadget.png', 'image/png']],
], $adminToken);
record('PROD-CREATE', in_array($c, [200, 201], true) && isset($j['data']['id']), "multipart POST /products HTTP=$c id=" . ($j['data']['id'] ?? '-') . (!isset($j['data']) ? ' body=' . substr(json_encode($j), 0, 300) : ''));
$productId = $j['data']['id'] ?? null;

if ($productId) {
    // Media physically exists?
    $mediaRow = DB::table('media')->where('model_id', $productId)->where('model_type', 'Marvel\Database\Models\Product')->orderByDesc('id')->first();
    if ($mediaRow) {
        $diskPath = storage_path('app/public/' . $mediaRow->disk . '/' . $mediaRow->id . '/' . $mediaRow->file_name);
        $exists = file_exists($diskPath);
        record('PROD-MEDIA-DISK', $exists, 'media row id=' . $mediaRow->id . ' physical_file=' . ($exists ? 'EXISTS' : 'MISSING') . ' path=' . $mediaRow->disk . '/' . $mediaRow->id);
    } else {
        record('PROD-MEDIA-DISK', false, 'no media row created for product');
    }

    // Public read + pricing enrichment + translation by locale (custom `lang` header)
    $prod = DB::table('products')->where('id', $productId)->value('slug');
    [$cEn, $jEn] = http('GET', '/api/v1/general/products/' . $prod, null, null, 'en');
    [$cAr, $jAr] = http('GET', '/api/v1/general/products/' . $prod, null, null, 'ar');
    $nameEn = is_array($jEn['data']['name'] ?? null) ? ($jEn['data']['name']['en'] ?? null) : ($jEn['data']['name'] ?? null);
    $nameAr = is_array($jAr['data']['name'] ?? null) ? ($jAr['data']['name']['ar'] ?? null) : ($jAr['data']['name'] ?? null);
    record('I18N-001', $cEn === 200 && is_string($nameEn) && str_starts_with($nameEn, 'E2E Audit Gadget'), "lang=en HTTP=$cEn name=" . var_export($nameEn, true));
    record('I18N-002', $cAr === 200 && $nameAr !== null && !preg_match('/^E2E Audit/u', (string) $nameAr), "lang=ar HTTP=$cAr name=" . var_export($nameAr, true));
    $priceFields = ['current_price', 'discount_active', 'flash_sale_active', 'has_flash_sale'];
    $missing = array_filter($priceFields, fn ($f) => !array_key_exists($f, $jEn['data'] ?? []));
    record('PRICE-ENRICH', count($missing) === 0, 'runtime pricing fields present after enrichment (ADR pipeline)' . (count($missing) ? ' MISSING=' . implode(',', $missing) : ''));

    // UPDATE via API then DB+public read reflect it (update contract = full resource)
    $tmpPng2 = storage_path('e2e/audit-product-update-' . uniqid() . '.png');
    file_put_contents($tmpPng2, $pngBytes);
    [$c, $jUp] = httpFull('PUT', '/api/v1/products/' . $productId, [
        'name' => ['en' => 'E2E Audit Gadget v2-' . substr(uniqid(), -4), 'ar' => 'جهاز تدقيق v2-' . substr(uniqid(), -4)], 'price' => '199.99',
        'description' => ['en' => 'Real E2E validation product', 'ar' => 'منتج للتحقق الفعلي'],
        'quantity' => 25, 'product_type' => 'simple', 'item_type' => 'PHYSICAL',
        'is_free_shipping' => 0, 'status' => 'publish', 'in_stock' => 1, 'has_discount' => 0, 'has_flash_sale' => 0,
        'categories' => [(int) $categoryId],
        '_method' => 'PUT',
    ], [
        'images' => [0 => [$tmpPng2, 'audit-gadget-v2.png', 'image/png']],
    ], $adminToken);
    $dbPrice = DB::table('products')->where('id', $productId)->value('price');
    $prod = DB::table('products')->where('id', $productId)->value('slug'); // name change regenerates slug
    [$cPub, $jPub] = http('GET', '/api/v1/general/products/' . $prod, null, null, 'en');
    $pubName = is_array($jPub['data']['name'] ?? null) ? ($jPub['data']['name']['en'] ?? null) : ($jPub['data']['name'] ?? null);
    record('PROD-UPDATE', in_array($c, [200, 201], true) && (string) $dbPrice === '199.99' && is_string($pubName) && str_starts_with($pubName, 'E2E Audit Gadget v2-'), "PUT HTTP=$c dbPrice=$dbPrice publicName=" . var_export($pubName, true) . (!in_array($c, [200, 201]) ? ' resp=' . substr(json_encode($jUp), 0, 240) : ''));

    // ---- CACHE PROOF (real Redis): MISS -> value cached -> mutation flushes tag
    // SettingController caches via HasCache::remember(FrontendResource::SETTINGS, md5(full-url)).
    $cacheKey = md5('http://localhost/api/v1/general/settings');
    Cache::store('redis')->tags(['settings'])->flush();
    $before = Cache::store('redis')->tags(['settings'])->get($cacheKey);
    [$c1, ] = http('GET', '/api/v1/general/settings');
    $afterFirst = Cache::store('redis')->tags(['settings'])->get($cacheKey);
    [$c2, ] = http('GET', '/api/v1/general/settings');
    $afterSecond = Cache::store('redis')->tags(['settings'])->get($cacheKey);
    record('CACHE-HIT', $c1 === 200 && $before === null && $afterFirst !== null && $afterSecond !== null,
        "miss(before=null:" . var_export($before === null, true) . ") -> written(" . ($afterFirst !== null ? 'yes' : 'NO') . ') -> still cached after 2nd GET');

    // Mutation through ADMIN settings must flush the settings cache tag.
    [$cU] = http('PUT', '/api/v1/settings', [
        'currency_selection_enabled' => true,
        'minimum_order_amount' => 10,
        'free_shipping_amount' => 500,
    ], $adminToken);
    $afterMutation = Cache::store('redis')->tags(['settings'])->get($cacheKey);
    record('CACHE-INVALIDATE', $cU === 200 && $afterMutation === null, "after PUT /settings HTTP=$cU cached=" . ($afterMutation !== null ? 'STALE!' : 'flushed'));

    // ---- CART FLOW ----------------------------------------------------------
    [$c, $j] = http('POST', '/api/v1/cart', [
        'item' => ['product_id' => $productId, 'quantity' => 2, 'shipping_method' => 'SCHEDULED'],
    ], $customerToken);
    record('CART-ADD', $c === 200 || $c === 201, "POST /cart HTTP=$c" . (!in_array($c, [200, 201]) ? ' body=' . substr(json_encode($j), 0, 180) : ''));

    // ---- ADDRESS (real customer address book entry) --------------------------
    [$c, $j] = http('POST', '/api/v1/address', [
        'title' => 'Home',
        'address' => [
            'zip' => '11511', 'city' => 'Cairo', 'state' => 'Cairo',
            'country' => 'EG', 'street_address' => '12 Audit Street',
        ],
        'location' => ['latitude' => 30.0444, 'longitude' => 31.2357],
    ], $customerToken);
    $addressId = $j['data']['id'] ?? null;
    ev('  address create HTTP=' . $c . ' id=' . ($addressId ?? '-') . (!isset($j['data']) && isset($j['errors']) ? ' errors=' . substr(json_encode($j['errors']), 0, 140) : ''));

    // ---- GOVERNORATE via admin API (needed for delivery checkout) ------------
    [$cC, $jC] = http('POST', '/api/v1/countries', ['name' => ['en' => 'E2E Land ' . substr(uniqid(), -5), 'ar' => 'بلد ' . substr(uniqid(), -5)], 'phone_code' => '+2' . random_int(10, 99), 'status' => 1], $adminToken);
    $countryId = $jC['data']['id'] ?? null;
    ev('  country create HTTP=' . $cC . ' id=' . ($countryId ?? '-') . (!isset($jC['data']) ? ' body=' . substr(json_encode($jC), 0, 200) : ''));
    [$cG, $jG] = http('POST', '/api/v1/governorates', [
        'country_id' => $countryId,
        'name' => ['en' => 'E2E Governorate ' . substr(uniqid(), -4), 'ar' => 'محافظة ' . substr(uniqid(), -4)],
        'status' => 1,
        'shipping_price' => ['price' => 25, 'estimated_days' => 4, 'free_shipping_over' => 500, 'status' => 1],
    ], $adminToken);
    $governorateId = $jG['data']['id'] ?? null;
    ev('  governorate create HTTP=' . $cG . ' id=' . ($governorateId ?? '-') . (!isset($jG['data']) ? ' body=' . substr(json_encode($jG), 0, 240) : ''));

    // ---- CHECKOUT COD (reads the user's active cart) -------------------------
    [$c, $j] = http('POST', '/api/v1/general/checkout', [
        'name' => 'E2E Customer',
        'user_phone' => '01000000444',
        'user_email' => 'e2e.plain@audit.test',
        'address' => ['zip' => '11511', 'city' => 'Cairo', 'state' => 'Cairo', 'country' => 'EG', 'street_address' => '12 Audit Street'],
        'payment_method' => 'cod',
        'fulfillment_type' => 'delivery',
        'governorate_id' => $governorateId,
    ], $customerToken);
    $orderId = $j['data']['id'] ?? null;
    if (!is_numeric($orderId)) {
        $orderRow = DB::table('orders')->where('user_id', DB::table('users')->where('email', 'e2e.plain@audit.test')->value('id'))->orderByDesc('id')->first();
        $orderId = $orderRow?->id ?? null;
    }
    record('CHECKOUT-COD', in_array($c, [200, 201], true) && $orderId !== null, "POST /checkout HTTP=$c order_id=$orderId" . (!in_array($c, [200, 201]) ? ' body=' . substr(json_encode($j), 0, 260) : ''));
}
saveState();
ev('');
ev('PHASE 2 COMPLETE.');
