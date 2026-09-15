<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Services\General\ProductService;
use Illuminate\Http\Request;

app()->setLocale('en');
$service = app(ProductService::class);

$cases = [
    ['category=electronics',               ['category' => 'electronics'],        'category_product'],
    ['brand=nike (singular)',              ['brand' => 'nike'],                   'brand_product'],
    ['brands=nike (plural, documented)',   ['brands' => 'nike'],                  'brand_product'],
    ['minPrice=10&maxPrice=100',           ['minPrice' => 10, 'maxPrice' => 100], 'products"."price'],
    ['price_min=10&price_max=100',         ['price_min' => 10, 'price_max' => 100], 'products"."price'],
    ['min_price=10&max_price=100 (guide)', ['min_price' => 10, 'max_price' => 100], 'products"."price'],
    ['rating_min=3',                       ['rating_min' => 3],                   '"rating"'],
    ['rating=4',                           ['rating' => 4],                       'reviews'],
    ['productsId=1,2,3',                   ['productsId' => '1,2,3'],             '"id" in'],
    ['categoriesId=5',                     ['categoriesId' => 5],                 'categories"."id'],
    ['brandsId=7',                         ['brandsId' => 7],                     'brands"."id'],
    ['tag=summer',                         ['tag' => 'summer'],                   'product_tag'],
    ['tags=summer,winter',                 ['tags' => 'summer,winter'],           'product_tag'],
    ['promotion=black-friday',             ['promotion' => 'black-friday'],       'promotion_product'],
    ['flash_sale=summer-sale',             ['flash_sale' => 'summer-sale'],       'flash_sale_products'],
    ['banner=hero',                        ['banner' => 'hero'],                  'banner_product'],
    ['slider=home',                        ['slider' => 'home'],                  'slider_product'],
    ['height_min=5&height_max=20',         ['height_min' => 5, 'height_max' => 20], 'REGEXP_REPLACE'],
    ['height=10 (exact)',                  ['height' => 10],                      'products"."height'],
    ['status=0 (public endpoint)',         ['status' => 0],                       'status"] = 0'],
    ['search=phone',                       ['search' => 'phone'],                 'json_extract'],
];

$base = $service->buildFilteredBaseQuery(new Request([]))->toSql();

echo str_pad('PARAMETERS', 42) . ' | ' . str_pad('SQL CHANGED', 12) . ' | EVIDENCE' . PHP_EOL;
echo str_repeat('-', 100) . PHP_EOL;

foreach ($cases as $case) {
    $label = $case[0];
    $params = $case[1];
    $evidence = $case[2];

    $request = new Request($params);
    $sql = $service->buildFilteredBaseQuery($request)->toSql();

    $changed = ($sql !== $base) ? 'YES' : 'NO';
    $inSql = (stripos($sql, $evidence) !== false) ? 'IN SQL' : 'ABSENT';

    echo str_pad($label, 42) . ' | ' . str_pad($changed, 12) . ' | ' . $inSql . PHP_EOL;
}
