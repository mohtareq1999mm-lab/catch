<?php

use Marvel\Database\Models\Banner;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\FlashSale;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Promotion;
use Marvel\Database\Models\Slider;
use Marvel\Database\Models\Tag;

// =====================================================================
// PHASE 15 — PUBLIC ENDPOINTS: EVERY SECTION TYPE WITH REAL DB DATA
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 15 — PUBLIC ENDPOINTS: EVERY SECTION TYPE');

// active storefront page
[$code, $json] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Public Storefront', 'ar' => 'الواجهة العامة']], $adminToken);
$storefront = $json['data']['id'];
ev('  storefront page id=' . $storefront . ' slug=public-storefront');

// realistic backing entities
$banner = Banner::create(['title' => 'Black Friday Mega Sale', 'status' => true]);
$slider = Slider::create(['title' => ['en' => 'Spring Collection', 'ar' => 'تشكيلة الربيع'], 'slug' => 'spring-collection', 'status' => true]);
$promotion = Promotion::create([
    'name' => ['en' => 'Ramadan Special', 'ar' => 'عرض رمضان'],
    'slug' => 'ramadan-special',
    'code' => 'RAMADAN25',
    'type' => 'price',
    'type_amount' => 'percentage',
    'value' => 25,
    'discount' => 25,
    'minimum_order_amount' => 0,
    'apply_to' => 'specific_products',
    'status' => true,
    'start_at' => now()->subDay()->format('Y-m-d'),
    'end_at' => now()->addDay()->format('Y-m-d'),
]);
$tag = Tag::create(['name' => ['en' => 'Deals', 'ar' => 'عروض'], 'slug' => 'deals']);
$category = Category::create(['name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'], 'slug' => 'electronics']);
$product = Product::create([
    'name' => ['en' => 'Wireless Headphones', 'ar' => 'سماعات لاسلكية'],
    'slug' => 'wireless-headphones-' . uniqid(),
    'price' => 249.99,
    'status' => 'publish',
    'in_stock' => true,
    'stock_quantity' => 50,
    'reserved_quantity' => 0,
    'product_type' => 'simple',
    'has_discount' => false,
    'has_flash_sale' => false,
    'is_fast_shipping_available' => false,
]);
$flashSale = FlashSale::create([
    'title' => 'Summer Mega Flash',
    'slug' => 'summer-mega-flash',
    'discount' => 30,
    'type' => 'percentage',
    'start_date' => now()->subDay(),
    'end_date' => now()->addMonth(),
    'status' => true,
]);
$brand = Brand::create(['name' => ['en' => 'Samsung', 'ar' => 'سامسونج'], 'slug' => 'samsung']);
$coupon = Coupon::create([
    'code' => 'AUDIT50',
    'name' => 'Audit Coupon',
    'slug' => 'audit-coupon',
    'type' => 'general',
    'discount_type' => 'percentage',
    'discount' => 50,
    'status' => true,
    'start_date' => now()->subDay(),
    'end_date' => now()->addMonth(),
]);
$entityIds = [
    'banners' => $banner->id, 'sliders' => $slider->id, 'promotions' => $promotion->id,
    'tags' => $tag->id, 'categories' => $category->id, 'products' => $product->id,
    'flash-sales' => $flashSale->id, 'brands' => $brand->id, 'coupons' => $coupon->id,
];
ev('  entities created: ' . json_encode($entityIds));

$typeConfig = [
    'banners' => ['setting' => ['front' => [], 'back' => ['bannersId' => [$banner->id]]], 'title' => ['en' => 'Hero Banners', 'ar' => 'بانرات رئيسية']],
    'sliders' => ['setting' => null, 'title' => ['en' => 'Home Sliders', 'ar' => 'سلايدرات رئيسية']],
    'promotions' => ['setting' => null, 'title' => ['en' => 'Active Promotions', 'ar' => 'عروض نشطة']],
    'tags' => ['setting' => null, 'title' => ['en' => 'Popular Tags', 'ar' => 'وسوم شائعة']],
    'categories' => ['setting' => null, 'title' => ['en' => 'Featured Categories', 'ar' => 'التصنيفات المميزة']],
    'products' => ['setting' => ['front' => [], 'back' => ['limit' => 5, 'type' => 'new_arrivals']], 'title' => ['en' => 'New Arrivals', 'ar' => 'وصل حديثاً']],
    'flash-sales' => ['setting' => null, 'title' => ['en' => 'Flash Sales', 'ar' => 'تخفيضات خاطفة']],
    'brands' => ['setting' => null, 'title' => ['en' => 'Featured Brands', 'ar' => 'علامات مميزة']],
    'coupons' => ['setting' => null, 'title' => ['en' => 'Coupons', 'ar' => 'كوبونات']],
];

$sectionByType = [];
foreach ($typeConfig as $type => $cfg) {
    $payload = ['type' => $type, 'title' => $cfg['title'], 'is_active' => 1, 'title_visible' => 1];
    if ($cfg['setting'] !== null) {
        $payload['setting'] = $cfg['setting'];
    }
    [$sc, $sj] = http('POST', '/api/v1/sections', $payload, $adminToken);
    $sectionByType[$type] = $sj['data']['id'];
}
[$code] = http('POST', '/api/v1/content-pages/' . $storefront . '/attach-sections', ['sections' => array_values($sectionByType)], $adminToken);
ev('  attached ' . count($sectionByType) . ' sections to storefront (HTTP=' . $code . ')');

// read public page -> endpoints
[$code, $json] = http('GET', '/api/v1/general/content-pages/public-storefront');
$pubSections = $json['data']['sections'] ?? [];
ev('  public storefront HTTP=' . $code . ' section count=' . count($pubSections));

$tableFor = [
    'banners' => 'banners', 'sliders' => 'sliders', 'promotions' => 'promotions',
    'tags' => 'tags', 'categories' => 'categories', 'products' => 'products',
    'flash-sales' => 'flash_sales', 'brands' => 'brands', 'coupons' => 'coupons',
];

ev('  | type | section DB id | generated endpoint | HTTP | returned ids | ids exist in DB | result |');
foreach ($pubSections as $s) {
    $type = $s['type'];
    $endpoint = $s['endpoint'];
    [$hc, $hj] = http('GET', '/api/v1/' . $endpoint);
    $payload = $hj['data'] ?? [];
    $ids = $type === 'products' ? array_column($payload['data'] ?? [], 'id') : array_column($payload, 'id');
    $exist = 0;
    foreach ($ids as $eid) {
        if (DB::table($tableFor[$type])->where('id', $eid)->exists()) {
            $exist++;
        }
    }
    $ok = $hc === 200 && $exist === count($ids) && count($ids) > 0;
    record('TC-PE-' . strtoupper(str_replace('-', '_', $type)), $ok, $type);
    ev('  | ' . $type . ' | ' . $s['id'] . ' | ' . $endpoint . ' | ' . $hc . ' | ' . json_encode($ids) . ' | ' . $exist . '/' . count($ids) . ' | ' . ($ok ? 'PASS' : 'FAIL') . ' |');
}

// verify products section returned the REAL product by id
$productsData = null;
foreach ($pubSections as $s) {
    if ($s['type'] === 'products') {
        [$hc, $hj] = http('GET', '/api/v1/' . $s['endpoint']);
        $productsData = $hj['data']['data'] ?? [];
    }
}
$prodIds = array_column($productsData, 'id');
record('TC-PE-PRODUCTS-REAL', in_array($product->id, $prodIds, true), 'created product id ' . $product->id . ' returned by generated endpoint');

// =====================================================================
// PHASE 16 — ENDPOINT GENERATION (stored endpoint must be ignored)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 16 — ENDPOINT GENERATION');

[$code, $json] = http('POST', '/api/v1/sections', [
    'type' => 'sliders',
    'title' => ['en' => 'Ignored Endpoint Slider', 'ar' => 'سلايدر نقطة وصول متجاهلة'],
    'endpoint' => 'general/WRONG',
    'is_active' => 1,
    'title_visible' => 1,
], $adminToken);
$wrongSec = $json['data']['id'];
[$code] = http('POST', '/api/v1/content-pages/' . $storefront . '/attach-sections', ['sections' => [$wrongSec]], $adminToken);
[$code, $json] = http('GET', '/api/v1/general/content-pages/public-storefront');
$endpoints = array_column($json['data']['sections'] ?? [], 'endpoint');
ev('  stored endpoint=general/WRONG; public page endpoints: ' . json_encode($endpoints));
$ok = in_array('general/sliders?', $endpoints, true) && !in_array('general/WRONG', $endpoints, true);
record('TC-EG-001', $ok, 'stored value ignored; generated general/sliders? authoritative');
[$code, $json] = http('GET', '/api/v1/general/sliders');
$slugs = array_column($json['data'] ?? [], 'slug');
record('TC-EG-002', $code === 200 && in_array('spring-collection', $slugs, true), 'generated endpoint returns real DB slider');

// =====================================================================
// PHASE 17 — TRANSLATIONS (stored JSON + en/ar API responses)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 17 — TRANSLATIONS');

$row = DB::table('content_pages')->where('id', $cp001)->value('title');
ev('  content_pages.title stored JSON: ' . $row);
$secRow = DB::table('sections')->where('id', $sectionByType['categories'])->value('title');
ev('  sections.title stored JSON:      ' . $secRow);

[$code, $json] = http('GET', '/api/v1/general/content-pages/home-electronics', null, null, 'en');
$enTitle = $json['data']['title'] ?? null;
[$code, $json] = http('GET', '/api/v1/general/content-pages/home-electronics', null, null, 'ar');
$arTitle = $json['data']['title'] ?? null;
ev('  locale=en title=' . $enTitle . ' | locale=ar title=' . $arTitle);
record('TC-TR-001', $enTitle === 'Home Electronics' && $arTitle === 'الرئيسية إلكترونيات', 'content page title en/ar via API');

// section title translations inside public page
[$code, $json] = http('GET', '/api/v1/general/content-pages/public-storefront', null, null, 'en');
$secEnRow = collect($json['data']['sections'] ?? [])->firstWhere('type', 'categories');
$secEn = $secEnRow['title'] ?? null;
[$code, $json] = http('GET', '/api/v1/general/content-pages/public-storefront', null, null, 'ar');
$secArRow = collect($json['data']['sections'] ?? [])->firstWhere('type', 'categories');
$secAr = $secArRow['title'] ?? null;
ev('  section title locale=en=' . var_export($secEn, true) . ' | locale=ar=' . var_export($secAr, true));
record('TC-TR-002', $secEn === 'Featured Categories' && $secAr === 'التصنيفات المميزة', 'section title en/ar via public API');