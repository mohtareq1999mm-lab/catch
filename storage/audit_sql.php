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
    'no params'                          => [],
    'category=electronics'               => ['category' => 'electronics'],
    'brand=nike (singular)'              => ['brand' => 'nike'],
    'brands=nike (plural, documented)'   => ['brands' => 'nike'],
    'minPrice=10&maxPrice=100'           => ['minPrice' => 10, 'maxPrice' => 100],
    'price_min=10&price_max=100'         => ['price_min' => 10, 'price_max' => 100],
    'min_price=10&max_price=100 (guide)' => ['min_price' => 10, 'max_price' => 100],
    'rating_min=3'                       => ['rating_min' => 3],
    'rating=4'                           => ['rating' => 4],
    'productsId=1,2,3'                   => ['productsId' => '1,2,3'],
    'categoriesId=5'                     => ['categoriesId' => 5],
    'brandsId=7'                         => ['brandsId' => 7],
    'tag=summer'                         => ['tag' => 'summer'],
    'tags=summer,winter'                 => ['tags' => 'summer,winter'],
    'promotion=black-friday'             => ['promotion' => 'black-friday'],
    'flash_sale=summer-sale'             => ['flash_sale' => 'summer-sale'],
    'banner=hero'                        => ['banner' => 'hero'],
    'slider=home'                        => ['slider' => 'home'],
    'height_min=5&height_max=20'         => ['height_min' => 5, 'height_max' => 20],
    'height=10 (exact)'                  => ['height' => 10],
    'status=0 (public endpoint)'         => ['status' => 0],
    'search=phone'                       => ['search' => 'phone'],
];

foreach ($cases as $label => $params) {
    $request = new Request($params);
    $query = $service->buildFilteredBaseQuery($request);
    $sql = $query->toSql();
    // Highlight presence of WHERE conditions
    $hasWhere = stripos($sql, 'where') !== false;
    echo "=== {$label} ===" . PHP_EOL;
    echo "  SQL: {$sql}" . PHP_EOL;
    echo '  bindings: ' . json_encode($query->getBindings()) . PHP_EOL;
    echo PHP_EOL;
}
