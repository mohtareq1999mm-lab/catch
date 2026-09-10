<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Marvel\Enums\ItemType;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;

echo "=== RUN 0 PRECHECK ===\n";
echo "Driver: " . config('database.default') . "\n";
$c = config('database.connections.' . config('database.default'));
echo "Host: " . ($c['host'] ?? 'N/A') . "\n";
echo "Port: " . ($c['port'] ?? 'N/A') . "\n";
echo "Database: " . ($c['database'] ?? 'N/A') . "\n";
echo "Username: " . ($c['username'] ?? 'N/A') . "\n";
echo "Env: " . app()->environment() . "\n";
echo "\n";
echo "products.item_type exists: " . (Schema::hasColumn('products', 'item_type') ? 'YES' : 'NO') . "\n";
if (Schema::hasColumn('products', 'item_type')) {
    $col = DB::select('SHOW COLUMNS FROM products LIKE "item_type"');
    print_r($col);
}
echo "ItemType PHYSICAL: " . (in_array('PHYSICAL', ItemType::getValues()) ? 'YES' : 'NO') . "\n";
echo "ItemType DIGITAL: " . (in_array('DIGITAL', ItemType::getValues()) ? 'YES' : 'NO') . "\n";
echo "ItemType values: " . implode(', ', ItemType::getValues()) . "\n";
echo "\n";
echo "Counts:\n";
echo "categories: " . DB::table('categories')->count() . "\n";
echo "brands: " . DB::table('brands')->count() . "\n";
echo "products: " . DB::table('products')->count() . "\n";
echo "variants: " . DB::table('product_variants')->count() . "\n";
echo "media: " . DB::table('media')->count() . "\n";
echo "\n";
foreach (['electronics','phones','smartphones','iphone'] as $s) {
    $c = Category::where('slug', $s)->first();
    if ($c) {
        echo "Category $s: ID {$c->id}, parent {$c->parent_id}, name " . json_encode($c->name) . "\n";
    } else {
        echo "Category $s: NOT FOUND\n";
    }
}
foreach (['acme-audio','nordic-home'] as $s) {
    $b = Brand::where('slug', $s)->first();
    if ($b) {
        echo "Brand $s: ID {$b->id}, name " . json_encode($b->name) . "\n";
    } else {
        echo "Brand $s: NOT FOUND\n";
    }
}
foreach (['PRD-SAMPLE-001','PRD-SAMPLE-002','PRD-SAMPLE-003'] as $sku) {
    $p = Product::where('sku', $sku)->first();
    if ($p) {
        echo "Product $sku: ID {$p->id}, item_type {$p->item_type}, price {$p->price}, slug {$p->slug}, quantity {$p->quantity}\n";
    } else {
        echo "Product $sku: NOT FOUND\n";
    }
}
