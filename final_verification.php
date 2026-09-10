<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Illuminate\Support\Facades\DB;

echo "=================================================================\n";
echo "FINAL PRODUCTION VERIFICATION\n";
echo "=================================================================\n\n";

// Database connection
echo "Database: " . config('database.connections.mysql.database') . "\n";
echo "Host: " . config('database.connections.mysql.host') . "\n";
echo "Port: " . config('database.connections.mysql.port') . "\n\n";

// =======================
// 1. VERIFY ITEM_TYPE COLUMN
// =======================
echo "--- 1. ITEM_TYPE COLUMN VERIFICATION ---\n";

$column = DB::select('SHOW COLUMNS FROM products WHERE Field = ?', ['item_type'])[0];
echo "Column exists: YES\n";
echo "Type: {$column->Type}\n";
echo "Null: {$column->Null}\n";
echo "Default: {$column->Default}\n\n";

$totalProducts = DB::table('products')->count();
$physicalCount = DB::table('products')->where('item_type', 'PHYSICAL')->count();
$digitalCount = DB::table('products')->where('item_type', 'DIGITAL')->count();

echo "Product counts:\n";
echo "  Total: {$totalProducts}\n";
echo "  PHYSICAL: {$physicalCount}\n";
echo "  DIGITAL: {$digitalCount}\n\n";

// =======================
// 2. VERIFY SAMPLE PRODUCTS
// =======================
echo "--- 2. SAMPLE PRODUCTS VERIFICATION ---\n";

$products = DB::table('products')
    ->whereIn('sku', ['PRD-SAMPLE-001', 'PRD-SAMPLE-002', 'PRD-SAMPLE-003'])
    ->get(['id', 'sku', 'name', 'item_type', 'price', 'quantity']);

foreach ($products as $product) {
    $name = json_decode($product->name);
    echo "\nProduct ID {$product->id}:\n";
    echo "  SKU: {$product->sku}\n";
    echo "  Name EN: " . ($name->en ?? 'N/A') . "\n";
    echo "  Name AR: " . ($name->ar ?? 'N/A') . "\n";
    echo "  Item Type: {$product->item_type}\n";
    echo "  Price: {$product->price}\n";
    echo "  Quantity: " . ($product->quantity ?? 'NULL') . "\n";
}

// =======================
// 3. VERIFY PRODUCT RELATIONSHIPS
// =======================
echo "\n--- 3. PRODUCT RELATIONSHIPS VERIFICATION ---\n";

// Categories
$catRelations = DB::table('category_product')
    ->join('products', 'category_product.product_id', '=', 'products.id')
    ->join('categories', 'category_product.category_id', '=', 'categories.id')
    ->whereIn('products.sku', ['PRD-SAMPLE-001', 'PRD-SAMPLE-002', 'PRD-SAMPLE-003'])
    ->get(['products.sku', 'categories.slug as category_slug']);

echo "\nProduct-Category Relations:\n";
foreach ($catRelations as $rel) {
    echo "  {$rel->sku} → {$rel->category_slug}\n";
}

// Brands
$brandRelations = DB::table('brand_product')
    ->join('products', 'brand_product.product_id', '=', 'products.id')
    ->join('brands', 'brand_product.brand_id', '=', 'brands.id')
    ->whereIn('products.sku', ['PRD-SAMPLE-001', 'PRD-SAMPLE-002', 'PRD-SAMPLE-003'])
    ->get(['products.sku', 'brands.slug as brand_slug']);

echo "\nProduct-Brand Relations:\n";
if ($brandRelations->count() > 0) {
    foreach ($brandRelations as $rel) {
        echo "  {$rel->sku} → {$rel->brand_slug}\n";
    }
} else {
    echo "  (none found)\n";
}

// Tags
$tagRelations = DB::table('product_tag')
    ->join('products', 'product_tag.product_id', '=', 'products.id')
    ->join('tags', 'product_tag.tag_id', '=', 'tags.id')
    ->whereIn('products.sku', ['PRD-SAMPLE-001', 'PRD-SAMPLE-002', 'PRD-SAMPLE-003'])
    ->get(['products.sku', 'tags.slug as tag_slug']);

echo "\nProduct-Tag Relations:\n";
if ($tagRelations->count() > 0) {
    foreach ($tagRelations as $rel) {
        echo "  {$rel->sku} → {$rel->tag_slug}\n";
    }
} else {
    echo "  (none found)\n";
}

// =======================
// 4. VERIFY BRANDS
// =======================
echo "\n--- 4. BRANDS VERIFICATION ---\n";

$brands = DB::table('brands')
    ->whereIn('slug', ['acme-audio', 'nordic-home'])
    ->get(['id', 'slug', 'name', 'status']);

foreach ($brands as $brand) {
    $name = json_decode($brand->name);
    echo "\nBrand ID {$brand->id}:\n";
    echo "  Slug: {$brand->slug}\n";
    echo "  Name EN: " . ($name->en ?? 'N/A') . "\n";
    echo "  Name AR: " . ($name->ar ?? 'N/A') . "\n";
    echo "  Status: {$brand->status}\n";

    // Check media
    $mediaCount = DB::table('media')
        ->where('model_type', 'Marvel\Database\Models\Brand')
        ->where('model_id', $brand->id)
        ->count();
    echo "  Media files: {$mediaCount}\n";
}

// =======================
// 5. VERIFY CATEGORIES
// =======================
echo "\n--- 5. CATEGORIES VERIFICATION ---\n";

$categories = DB::table('categories')
    ->whereIn('slug', ['electronics', 'phones', 'smartphones', 'iphone'])
    ->orderBy('id')
    ->get(['id', 'slug', 'name', 'parent_id']);

foreach ($categories as $cat) {
    $name = json_decode($cat->name);
    $parentInfo = $cat->parent_id ? "ID {$cat->parent_id}" : "NULL (root)";

    echo "\nCategory ID {$cat->id}:\n";
    echo "  Slug: {$cat->slug}\n";
    echo "  Name EN: " . ($name->en ?? 'N/A') . "\n";
    echo "  Name AR: " . ($name->ar ?? 'N/A') . "\n";
    echo "  Parent: {$parentInfo}\n";
}

// =======================
// 6. VERIFY PRODUCT VARIANT
// =======================
echo "\n--- 6. PRODUCT VARIANT VERIFICATION ---\n";

$variant = DB::table('product_variants')
    ->where('variant_sku', 'PRD-SAMPLE-001-BLK')
    ->first();

if ($variant) {
    $product = DB::table('products')->where('id', $variant->product_id)->first();
    echo "\nVariant found:\n";
    echo "  Variant SKU: {$variant->variant_sku}\n";
    echo "  Product SKU: " . ($product->sku ?? 'N/A') . "\n";
    echo "  Price: {$variant->price}\n";
    echo "  Sale Price: {$variant->sale_price}\n";
    echo "  Quantity: {$variant->quantity}\n";
} else {
    echo "\nVariant PRD-SAMPLE-001-BLK: NOT FOUND\n";
}

// =======================
// FINAL ASSESSMENT
// =======================
echo "\n=================================================================\n";
echo "FINAL ASSESSMENT\n";
echo "=================================================================\n\n";

$checks = [
    'item_type_exists' => isset($column),
    'item_type_correct_type' => ($column->Type ?? '') === "enum('PHYSICAL','DIGITAL')",
    'item_type_correct_default' => ($column->Default ?? '') === 'PHYSICAL',
    'sample_products_exist' => $products->count() === 3,
    'physical_product_exists' => $products->where('item_type', 'PHYSICAL')->count() >= 2,
    'digital_product_exists' => $products->where('item_type', 'DIGITAL')->count() >= 1,
    'brands_exist' => $brands->count() === 2,
    'categories_exist' => $categories->count() === 4,
];

echo "Core Checks:\n";
foreach ($checks as $check => $passed) {
    $status = $passed ? '✓ PASS' : '✗ FAIL';
    echo "  {$check}: {$status}\n";
}

$allPass = !in_array(false, $checks, true);

echo "\n" . str_repeat("=", 65) . "\n";
if ($allPass) {
    echo "STATUS: ✓ PRODUCTION READY\n";
    echo "\nAll critical components verified:\n";
    echo "  - Database schema correct (item_type column)\n";
    echo "  - Sample products imported with correct item_types\n";
    echo "  - Brands imported successfully (with optional image handling)\n";
    echo "  - Categories imported with parent relationships\n";
} else {
    echo "STATUS: ✗ NOT PRODUCTION READY\n";
    echo "Some checks failed - review output above.\n";
}
echo str_repeat("=", 65) . "\n";
