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
    ['minPrice=10&maxPrice=100',           ['minPrice' => 10, 'maxPrice' => 100], 'price'],
    ['price_min=10&price_max=100',         ['price_min' => 10, 'price_max' => 100], 'price'],
    ['min_price=10&max_price=100 (guide)', ['min_price' => 10, 'max_price' => 100], 'price'],
    ['rating_min=3',                       ['rating_min' => 3],                   'rating'],
    ['rating=4',                           ['rating' => 4],                       'reviews'],
    ['productsId=1,2,3',                   ['productsId' => '1,2,3'],             'products"."id'],
    ['categoriesId=5',                     ['categoriesId' => 5],                 'categories'],
    ['brandsId=7',                         ['brandsId' => 7],                     'brands'],
    ['tag=summer',                         ['tag' => 'summer'],                   'product_tag'],
    ['tags=summer,winter',                 ['tags' => 'summer,winter'],           'product_tag'],
    ['promotion=black-friday',             ['promotion' => 'black-friday'],       'promotion'],
    ['flash_sale=summer-sale',             ['flash_sale' => 'summer-sale'],       'flash_sale'],
    ['banner=hero',                        ['banner' => 'hero'],                  'banner'],
    ['slider=home',                        ['slider' => 'home'],                  'slider'],
    ['height_min=5&height_max=20',         ['height_min' => 5, 'height_max' => 20], 'REGEXP_REPLACE'],
    ['height=10 (exact)',                  ['height' => 10],                      'height'],
    ['status=0 (public endpoint)',         ['status' => 0],                       'status'],
    ['search=phone',                       ['search' => 'phone'],                 'json_extract'],
    ['order_price=asc (sort only)',        ['order_price' => 'asc'],              'ORDER BY'],
    ['limit=5',                            ['limit' => 5],                        'limit'],
];

$baseSql = $service->buildFilteredBaseQuery(new Request([]))->toSql();
$baseLen = strlen($baseSql);

foreach ($cases as $case) {
    $label = $case[0];
    $params = $case[1];
    $evidence = $case[2];
    $request = new Request($params);
    $sql = $service->buildFilteredBaseQuery($request)->toSql();
    $changed = ($sql !== $baseSql) ? 'YES' : 'NO';
    $inSql = (stripos($sql, $evidence) !== false) ? 'IN_SQL' : 'ABSENT';
    $delta = strlen($sql) - $baseLen;
    echo $label . ' | changed=' . $changed . ' | evidence=' . $inSql . ' | delta_len=' . $delta . PHP_EOL;
}
