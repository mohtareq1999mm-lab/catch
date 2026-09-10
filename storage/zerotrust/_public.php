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
// PHASE 12 — PUBLIC ENDPOINTS: EVERY SECTION TYPE (real entities)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 12 — PUBLIC ENDPOINTS (per type)');

[$code, $j] = http('POST', '/api/v1/content-pages', ['title' => ['en' => 'Storefront', 'ar' => 'الواجهة']], $GLOBALS['adminToken']);
$storefront = $j['data']['id'];
ev('  storefront page id=' . $storefront . ' slug=storefront');

$banner = Banner::create(['title' => 'Mega Sale Banner', 'status' => true]);
$slider = Slider::create(['title' => ['en' => 'Spring', 'ar' => 'الربيع'], 'slug' => 'spring', 'status' => true]);
$promotion = Promotion::create([
    'name' => ['en' => 'Ramadan', 'ar' => 'رمضان'], 'slug' => 'ramadan', 'code' => 'RM25',
    'type' => 'price', 'type_amount' => 'percentage', 'value' => 25, 'discount' => 25,
    'minimum_order_amount' => 0, 'apply_to' => 'specific_products', 'status' => true,
    'start_at' => now()->subDay()->format('Y-m-d'), 'end_at' => now()->addDay()->format('Y-m-d'),
]);
$tag = Tag::create(['name' => ['en' => 'Deals', 'ar' => 'عروض'], 'slug' => 'deals']);
$category = Category::create(['name' => ['en' => 'Electronics', 'ar' => 'إلكترونيات'], 'slug' => 'electronics']);
$product = Product::create([
    'name' => ['en' => 'Headphones', 'ar' => 'سماعات'], 'slug' => 'headphones-' . uniqid(),
    'price' => 249.99, 'status' => 'publish', 'in_stock' => true, 'stock_quantity' => 50,
    'reserved_quantity' => 0, 'product_type' => 'simple', 'has_discount' => false,
    'has_flash_sale' => false, 'is_fast_shipping_available' => false,
]);
$flashSale = FlashSale::create([
    'title' => 'Flash', 'slug' => 'flash', 'discount' => 30, 'type' => 'percentage',
    'start_date' => now()->subDay(), 'end_date' => now()->addMonth(), 'status' => true,
]);
$brand = Brand::create(['name' => ['en' => 'Samsung', 'ar' => 'سامسونج'], 'slug' => 'samsung']);
$coupon = Coupon::create([
    'code' => 'ZT50', 'name' => 'Zt Coupon', 'slug' => 'zt-coupon', 'type' => 'general',
    'discount_type' => 'percentage', 'discount' => 50, 'status' => true,
    'start_date' => now()->subDay(), 'end_date' => now()->addMonth(),
]);
$entityIds = [
    'banners' => $banner->id, 'sliders' => $slider->id, 'promotions' => $promotion->id,
    'tags' => $tag->id, 'categories' => $category->id, 'products' => $product->id,
    'flash-sales' => $flashSale->id, 'brands' => $brand->id, 'coupons' => $coupon->id,
];
ev('  entities: ' . json_encode($entityIds));

$typeConfig = [
    'banners' => ['setting' => ['front' => [], 'back' => ['bannersId' => [$banner->id]]], 'title' => ['en' => 'Hero Banners', 'ar' => 'بانرات']],
    'sliders' => ['setting' => null, 'title' => ['en' => 'Home Sliders', 'ar' => 'سلايدرز']],
    'promotions' => ['setting' => null, 'title' => ['en' => 'Promotions', 'ar' => 'عروض']],
    'tags' => ['setting' => null, 'title' => ['en' => 'Popular Tags', 'ar' => 'وسوم']],
    'categories' => ['setting' => null, 'title' => ['en' => 'Categories', 'ar' => 'تصنيفات']],
    'products' => ['setting' => ['front' => [], 'back' => ['productsId' => [$product->id]]], 'title' => ['en' => 'Our Products', 'ar' => 'منتجاتنا']],
    'flash-sales' => ['setting' => null, 'title' => ['en' => 'Flash Sales', 'ar' => 'تخفيضات']],
    'brands' => ['setting' => null, 'title' => ['en' => 'Brands', 'ar' => 'علامات']],
    'coupons' => ['setting' => null, 'title' => ['en' => 'Coupons', 'ar' => 'كوبونات']],
];
$tableFor = [
    'banners' => 'banners', 'sliders' => 'sliders', 'promotions' => 'promotions',
    'tags' => 'tags', 'categories' => 'categories', 'products' => 'products',
    'flash-sales' => 'flash_sales', 'brands' => 'brands', 'coupons' => 'coupons',
];

$sectionByType = [];
foreach ($typeConfig as $type => $cfg) {
    $payload = ['type' => $type, 'title' => $cfg['title'], 'is_active' => 1, 'title_visible' => 1];
    if ($cfg['setting'] !== null) {
        $payload['setting'] = $cfg['setting'];
    }
    [$sc, $sj] = http('POST', '/api/v1/sections', $payload, $GLOBALS['adminToken']);
    $sectionByType[$type] = $sj['data']['id'];
}
http('POST', '/api/v1/content-pages/' . $storefront . '/attach-sections', ['sections' => array_values($sectionByType)], $GLOBALS['adminToken']);

[$code, $j] = http('GET', '/api/v1/general/content-pages/storefront');
$pubSections = $j['data']['sections'] ?? [];
ev('  storefront HTTP=' . $code . ' sections=' . count($pubSections));

ev('  | type | endpoint | HTTP | returned ids | ids exist in DB | result |');
foreach ($pubSections as $s) {
    $type = $s['type'];
    $endpoint = $s['endpoint'];
    [$hc, $hj] = http('GET', '/api/v1/' . $endpoint);
    $payload = $hj['data'] ?? [];
    $ids = ztIds($payload);
    $exist = 0;
    foreach ($ids as $eid) {
        if (DB::table($tableFor[$type])->where('id', $eid)->exists()) {
            $exist++;
        }
    }
    $ok = $hc === 200 && count($ids) > 0 && $exist === count($ids);
    record('TC-PE-' . strtoupper(str_replace('-', '_', $type)), $ok, $type);
    ev('  | ' . $type . ' | ' . $endpoint . ' | ' . $hc . ' | ' . json_encode($ids) . ' | ' . $exist . '/' . count($ids) . ' | ' . ($ok ? 'PASS' : 'FAIL') . ' |');
}
record('TC-PE-ALL', collect($pubSections)->count() === 9, 'all 9 section types attached + returned');

// products REAL proof
$prodIds = [];
foreach ($pubSections as $s) {
    if ($s['type'] === 'products') {
        [$hc, $hj] = http('GET', '/api/v1/' . $s['endpoint']);
        $prodIds = ztIds($hj['data'] ?? []);
    }
}
record('TC-PE-PRODUCTS-REAL', in_array($product->id, $prodIds, true), 'created product id ' . $product->id . ' returned by generated endpoint (exists in products table)');

// endpoint override proof
[$code, $sj] = http('POST', '/api/v1/sections', [
    'type' => 'sliders', 'title' => ['en' => 'Override Slider', 'ar' => 'متجاوز'],
    'endpoint' => 'general/WRONG', 'is_active' => 1, 'title_visible' => 1,
], $GLOBALS['adminToken']);
$wrongSec = $sj['data']['id'];
http('POST', '/api/v1/content-pages/' . $storefront . '/attach-sections', ['sections' => [$wrongSec]], $GLOBALS['adminToken']);
[$code, $j] = http('GET', '/api/v1/general/content-pages/storefront');
$endpoints = array_column($j['data']['sections'] ?? [], 'endpoint');
ev('  endpoints incl override attempt: ' . json_encode($endpoints));
record('TC-EG-001', count(array_filter($endpoints, fn ($e) => str_starts_with($e, 'general/sliders'))) > 0 && !in_array('general/WRONG', $endpoints, true), 'stored client endpoint cannot override generated endpoint');
[$code, $j] = http('GET', '/api/v1/general/sliders');
$slugs = array_column($j['data'] ?? [], 'slug');
record('TC-EG-002', $code === 200 && in_array('spring', $slugs, true), 'generated endpoint returns real DB slider');

// =====================================================================
// PHASE 12b — TRANSLATIONS (DB JSON vs API)
// =====================================================================
ev('');
ev('=================================================================');
ev('PHASE 12b — TRANSLATIONS');

$dbTitle = DB::table('content_pages')->where('slug', 'electronics')->value('title');
ev('  content_pages.title DB JSON: ' . $dbTitle);
[$code, $j] = http('GET', '/api/v1/general/content-pages/electronics', null, null, 'en');
$en = $j['data']['title'] ?? null;
[$code, $j] = http('GET', '/api/v1/general/content-pages/electronics', null, null, 'ar');
$ar = $j['data']['title'] ?? null;
$dbDecoded = json($dbTitle);
record('TC-TR-001', $en === $dbDecoded['en'] && $ar === $dbDecoded['ar'], 'page title lang=en => "' . $en . '" / lang=ar => "' . $ar . '" matches DB JSON exactly');

$secDbTitle = DB::table('sections')->where('id', $sectionByType['categories'])->value('title');
[$code, $j] = http('GET', '/api/v1/general/content-pages/storefront', null, null, 'ar');
$secAr = collect($j['data']['sections'] ?? [])->firstWhere('type', 'categories')['title'] ?? null;
record('TC-TR-002', $secAr === json($secDbTitle)['ar'], 'section title lang=ar "' . $secAr . '" matches DB JSON ar value exactly');