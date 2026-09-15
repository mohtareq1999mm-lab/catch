<?php

require __DIR__ . '/../vendor/autoload.php';
$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo 'total_products=' . DB::table('products')->count() . PHP_EOL;

echo 'by_status:' . PHP_EOL;
foreach (DB::table('products')->selectRaw('status, count(*) c')->groupBy('status')->get() as $r) {
    echo '  status=' . var_export($r->status, true) . ' count=' . $r->c . PHP_EOL;
}

echo 'by_in_stock:' . PHP_EOL;
foreach (DB::table('products')->selectRaw('in_stock, count(*) c')->groupBy('in_stock')->get() as $r) {
    echo '  in_stock=' . var_export($r->in_stock, true) . ' count=' . $r->c . PHP_EOL;
}

echo 'by_product_type:' . PHP_EOL;
foreach (DB::table('products')->selectRaw('product_type, count(*) c')->groupBy('product_type')->get() as $r) {
    echo '  product_type=' . var_export($r->product_type, true) . ' count=' . $r->c . PHP_EOL;
}

echo 'price min/max: ' . DB::table('products')->min('price') . ' / ' . DB::table('products')->max('price') . PHP_EOL;

echo 'categories=' . DB::table('categories')->count() . ' brands=' . DB::table('brands')->count() . ' tags=' . DB::table('tags')->count() . PHP_EOL;
echo 'products_with_categories=' . DB::table('category_product')->distinct('product_id')->count('product_id') . PHP_EOL;
echo 'products_with_brands=' . DB::table('brand_product')->distinct('product_id')->count('product_id') . PHP_EOL;
echo 'products_with_tags=' . DB::table('product_tag')->distinct('product_id')->count('product_id') . PHP_EOL;

// duplicate price values relevant to cursor sorting
echo 'distinct_prices=' . DB::table('products')->distinct('price')->count('price') . PHP_EOL;

// sample categories
echo '--- sample categories ---' . PHP_EOL;
foreach (DB::table('categories')->select('id', 'slug', 'status')->limit(15)->get() as $c) {
    echo '  cat id=' . $c->id . ' slug=' . $c->slug . ' status=' . var_export($c->status, true) . PHP_EOL;
}

echo '--- sample brands ---' . PHP_EOL;
foreach (DB::table('brands')->select('id', 'slug', 'status')->limit(10)->get() as $b) {
    echo '  brand id=' . $b->id . ' slug=' . $b->slug . ' status=' . var_export($b->status, true) . PHP_EOL;
}

echo '--- sample tags ---' . PHP_EOL;
foreach (DB::table('tags')->select('id', 'slug')->limit(10)->get() as $t) {
    echo '  tag id=' . $t->id . ' slug=' . $t->slug . PHP_EOL;
}
