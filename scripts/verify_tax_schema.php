<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Schema;

$checks = [
    'products.tax_enabled' => Schema::hasColumn('products','tax_enabled'),
    'products.tax_rate' => Schema::hasColumn('products','tax_rate'),
    'products.tax_class_id (should be false)' => !Schema::hasColumn('products','tax_class_id'),
    'settings.order_tax_enabled' => Schema::hasColumn('settings','order_tax_enabled'),
    'settings.order_tax_rate' => Schema::hasColumn('settings','order_tax_rate'),
    'settings.default_tax_class_id (should be false)' => !Schema::hasColumn('settings','default_tax_class_id'),
    'orders.product_taxable_amount' => Schema::hasColumn('orders','product_taxable_amount'),
    'orders.product_tax_amount' => Schema::hasColumn('orders','product_tax_amount'),
    'orders.order_tax_rate' => Schema::hasColumn('orders','order_tax_rate'),
    'orders.order_taxable_amount' => Schema::hasColumn('orders','order_taxable_amount'),
    'orders.order_tax_amount' => Schema::hasColumn('orders','order_tax_amount'),
    'orders.tax_rate (should be false)' => !Schema::hasColumn('orders','tax_rate'),
    'orders.tax_amount (should be false)' => !Schema::hasColumn('orders','tax_amount'),
    'orders.taxable_amount (should be false)' => !Schema::hasColumn('orders','taxable_amount'),
    'orders.tax_mode (should be false)' => !Schema::hasColumn('orders','tax_mode'),
    'order_products.product_tax_rate' => Schema::hasColumn('order_products','product_tax_rate'),
    'order_products.product_taxable_amount' => Schema::hasColumn('order_products','product_taxable_amount'),
    'order_products.product_tax_amount' => Schema::hasColumn('order_products','product_tax_amount'),
    'tax_classes table (should be false)' => !Schema::hasTable('tax_classes'),
];

foreach ($checks as $k=>$v) {
    echo ($v?'PASS':'FAIL')." $k\n";
}
