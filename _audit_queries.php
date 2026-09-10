<?php
require_once __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=== PROMOTION-PRODUCT RELATIONS ===\n";
$promoProducts = DB::table('promotion_product')
    ->join('promotions', 'promotions.id', '=', 'promotion_product.promotion_id')
    ->select('promotions.id as promo_id', 'promotions.name', 'promotions.apply_to', 'promotion_product.product_id')
    ->get();
foreach ($promoProducts as $p) { echo json_encode($p) . "\n"; }

echo "\n=== PRODUCT-CATEGORY ===\n";
$prodCats = DB::table('category_product')
    ->join('products', 'products.id', '=', 'category_product.product_id')
    ->join('categories', 'categories.id', '=', 'category_product.category_id')
    ->select('product_id', 'products.name as pname', 'category_id', 'categories.name as catname')
    ->limit(30)->get();
foreach ($prodCats as $p) { echo json_encode($p) . "\n"; }

echo "\n=== ATTRIBUTE VALUES (variation by size/color) ===\n";
$attrs = DB::table('attribute_values')
    ->select('id','attribute_id','product_id','value','price')
    ->where('product_id', '<=', 20)
    ->limit(50)->get();
foreach ($attrs as $a) { echo json_encode($a) . "\n"; }

echo "\n=== PRODUCT VARIANTS ===\n";
$variants = DB::table('product_variants')
    ->select('id','product_id','title','price','sale_price','sku','stock','is_default')
    ->where('product_id', '<=', 20)
    ->limit(50)->get();
foreach ($variants as $v) { echo json_encode($v) . "\n"; }

echo "\n=== CART ITEMS ===\n";
$cartItems = DB::table('cart_items')->limit(20)->get();
foreach ($cartItems as $ci) { echo json_encode($ci) . "\n"; }

echo "\n=== CARTS ===\n";
$carts = DB::table('carts')->limit(10)->get();
foreach ($carts as $c) { echo json_encode($c) . "\n"; }

echo "\n=== SETTINGS ===\n";
$settings = DB::table('settings')->select('id','options')->first();
if ($settings) {
    $opts = json_decode($settings->options);
    echo "currency: " . ($opts->currency ?? 'N/A') . "\n";
    echo "default_shipping: " . ($opts->default_shipping ?? 'N/A') . "\n";
    echo "min_order_amount: " . ($opts->min_order_amount ?? 'N/A') . "\n";
}

echo "\n=== SHIPPING PRICES ===\n";
$sp = DB::table('shipping_prices')->limit(10)->get();
foreach ($sp as $s) { echo json_encode($s) . "\n"; }

echo "\n=== ORDER PRODUCTS (last 5 orders) ===\n";
$orderItems = DB::table('order_products')
    ->join('orders', 'orders.id', '=', 'order_products.order_id')
    ->select('order_products.id', 'order_products.order_id', 'order_products.product_id', 'order_products.quantity', 'order_products.unit_price', 'order_products.subtotal')
    ->orderBy('order_products.id', 'desc')
    ->limit(20)->get();
foreach ($orderItems as $oi) { echo json_encode($oi) . "\n"; }

echo "\n=== TRANSACTIONS (last 5) ===\n";
$txns = DB::table('transactions')
    ->select('id','order_id','total','status','payment_gateway','transaction_id','created_at')
    ->orderBy('id', 'desc')
    ->limit(5)->get();
foreach ($txns as $t) { echo json_encode($t) . "\n"; }

echo "\n=== TOTAL PRODUCT COUNT: " . DB::table('products')->whereNull('deleted_at')->count() . " ===\n";
echo "=== TOTAL COUPONS: " . DB::table('coupons')->count() . " ===\n";
echo "=== TOTAL PROMOTIONS: " . DB::table('promotions')->count() . " ===\n";
echo "=== TOTAL ORDERS: " . DB::table('orders')->count() . " ===\n";
echo "=== TOTAL USERS: " . DB::table('users')->count() . " ===\n";
echo "=== TOTAL VARIANT PRODUCTS (product_variants): " . DB::table('product_variants')->count() . " ===\n";
echo "=== TOTAL SIMPLE PRODUCTS (no variants): " . DB::table('products')->where('product_type', 'simple')->whereNull('deleted_at')->count() . " ===\n";
echo "=== TOTAL VARIABLE PRODUCTS: " . DB::table('products')->where('product_type', 'variable')->whereNull('deleted_at')->count() . " ===\n";
echo "=== MIN PRODUCT PRICE: " . DB::table('products')->whereNull('deleted_at')->min('price') . " ===\n";
echo "=== MAX PRODUCT PRICE: " . DB::table('products')->whereNull('deleted_at')->max('price') . " ===\n";

echo "\n=== PROMOTION PRODUCT IDs (first 5 promotions) ===\n";
$promoIds = DB::table('promotion_product')
    ->select('promotion_id')
    ->distinct()
    ->get();
foreach ($promoIds as $pi) { echo "Promotion $pi->promotion_id:\n";
    $prods = DB::table('promotion_product')->where('promotion_id', $pi->promotion_id)->pluck('product_id');
    echo "  Products: " . $prods->implode(',') . "\n";
}
