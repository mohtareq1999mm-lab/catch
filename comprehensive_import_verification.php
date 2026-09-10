<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use Marvel\Services\Import\BrandImportService;
use Marvel\Services\Import\CategoryImportService;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Category;
use Marvel\Database\Models\Product;
use Illuminate\Support\Facades\DB;

echo "=================================================================\n";
echo "COMPREHENSIVE IMPORT VERIFICATION - THREE RUN TEST\n";
echo "=================================================================\n\n";

// =======================
// PRE-CHECK: Database State
// =======================
echo "--- PRE-CHECK: Database State ---\n";
echo "Database: " . config('database.connections.mysql.database') . "\n";
echo "Host: " . config('database.connections.mysql.host') . "\n\n";

$productsCount = DB::table('products')->count();
$brandsCount = DB::table('brands')->count();
$categoriesCount = DB::table('categories')->count();

echo "Existing records:\n";
echo "  Products: {$productsCount}\n";
echo "  Brands: {$brandsCount}\n";
echo "  Categories: {$categoriesCount}\n\n";

// Check if sample records exist
$sampleProducts = DB::table('products')
    ->whereIn('sku', ['PRD-SAMPLE-001', 'PRD-SAMPLE-002', 'PRD-SAMPLE-003'])
    ->pluck('sku')
    ->toArray();

$sampleBrands = DB::table('brands')
    ->whereIn('slug', ['acme-audio', 'nordic-home'])
    ->pluck('slug')
    ->toArray();

$sampleCategories = DB::table('categories')
    ->whereIn('slug', ['electronics', 'phones', 'smartphones', 'iphone'])
    ->pluck('slug')
    ->toArray();

echo "Sample records already exist:\n";
echo "  Products: " . implode(', ', $sampleProducts) . "\n";
echo "  Brands: " . implode(', ', $sampleBrands) . "\n";
echo "  Categories: " . implode(', ', $sampleCategories) . "\n\n";

// =======================
// TEST 1: Brand Import
// =======================
function testBrandImport($runNumber) {
    echo "\n=================================================================\n";
    echo "RUN #{$runNumber}: BRAND IMPORT TEST\n";
    echo "=================================================================\n\n";

    $filePath = __DIR__ . '/packages/marvel/resources/brands/brand-import-sample.xlsx';

    if (!file_exists($filePath)) {
        echo "ERROR: Brand sample file not found: {$filePath}\n";
        return false;
    }

    echo "File: {$filePath}\n";
    echo "File size: " . filesize($filePath) . " bytes\n\n";

    try {
        $service = new BrandImportService();

        // Load Excel
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray();

        $headers = array_shift($rows);
        echo "Headers: " . implode(', ', $headers) . "\n\n";

        // Convert to collection
        $headerMap = ['name_en', 'name_ar', 'details_en', 'details_ar', 'status', 'image_desktop_url', 'image_mobile_url'];
        $collection = collect($rows)->map(function($row) use ($headerMap) {
            return array_combine($headerMap, $row);
        });

        echo "Processing {$collection->count()} brand rows...\n";

        // Process
        $service->processRows($collection);

        // Get results via reflection
        $reflection = new \ReflectionClass($service);

        $successProp = $reflection->getProperty('successCount');
        $successProp->setAccessible(true);
        $successCount = $successProp->getValue($service);

        $failedProp = $reflection->getProperty('failedRows');
        $failedProp->setAccessible(true);
        $failedRows = $failedProp->getValue($service);

        echo "\n--- RESULTS ---\n";
        echo "Success Count: {$successCount}\n";
        echo "Failed Count: " . count($failedRows) . "\n";

        if (!empty($failedRows)) {
            echo "\nFailed Rows:\n";
            foreach ($failedRows as $fail) {
                $row = $fail['excel_row'] ?? $fail['row'] ?? 'unknown';
                $name = $fail['name_en'] ?? 'N/A';
                $error = $fail['error'] ?? $fail['error_message'] ?? json_encode($fail);
                echo "  - Row {$row} ({$name}): {$error}\n";
            }
        }

        // Verify in database
        echo "\n--- DATABASE VERIFICATION ---\n";
        $brands = Brand::whereIn('slug', ['acme-audio', 'nordic-home'])->get();

        foreach ($brands as $brand) {
            $nameData = json_decode($brand->name, true);
            $nameEn = $nameData['en'] ?? 'N/A';
            $nameAr = $nameData['ar'] ?? 'N/A';

            echo "\nBrand: {$nameEn} / {$nameAr}\n";
            echo "  Slug: {$brand->slug}\n";
            echo "  Status: {$brand->status}\n";
            echo "  Has desktop image: " . ($brand->hasMedia('brands-desktop') ? 'YES' : 'NO') . "\n";
            echo "  Has mobile image: " . ($brand->hasMedia('brands-mobile') ? 'YES' : 'NO') . "\n";
        }

        return $successCount === 2 && count($failedRows) === 0;

    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        return false;
    }
}

// =======================
// TEST 2: Category Import
// =======================
function testCategoryImport($runNumber) {
    echo "\n=================================================================\n";
    echo "RUN #{$runNumber}: CATEGORY IMPORT TEST\n";
    echo "=================================================================\n\n";

    $filePath = __DIR__ . '/packages/marvel/resources/categories/category-import-sample.xlsx';

    if (!file_exists($filePath)) {
        echo "ERROR: Category sample file not found: {$filePath}\n";
        return false;
    }

    echo "File: {$filePath}\n";
    echo "File size: " . filesize($filePath) . " bytes\n\n";

    try {
        $service = new CategoryImportService();

        // Load Excel
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $spreadsheet = $reader->load($filePath);
        $sheet = $spreadsheet->getSheet(0);
        $rows = $sheet->toArray();

        $headers = array_shift($rows);
        echo "Headers: " . implode(', ', $headers) . "\n\n";

        // Convert to collection
        $headerMap = ['name_en', 'name_ar', 'details_en', 'details_ar', 'parent_name_en', 'status', 'is_featured', 'image_desktop_url', 'image_mobile_url'];
        $collection = collect($rows)->map(function($row) use ($headerMap) {
            return array_combine($headerMap, $row);
        });

        echo "Processing {$collection->count()} category rows...\n";

        // Process
        $service->processRows($collection);

        // Get results via reflection
        $reflection = new \ReflectionClass($service);

        $successProp = $reflection->getProperty('successCount');
        $successProp->setAccessible(true);
        $successCount = $successProp->getValue($service);

        $failedProp = $reflection->getProperty('failedRows');
        $failedProp->setAccessible(true);
        $failedRows = $failedProp->getValue($service);

        echo "\n--- RESULTS ---\n";
        echo "Success Count: {$successCount}\n";
        echo "Failed Count: " . count($failedRows) . "\n";

        // Verify in database
        echo "\n--- DATABASE VERIFICATION ---\n";
        $categories = Category::whereIn('slug', ['electronics', 'phones', 'smartphones', 'iphone'])
            ->orderBy('id')
            ->get();

        foreach ($categories as $cat) {
            $nameData = json_decode($cat->name, true);
            $nameEn = $nameData['en'] ?? 'N/A';
            $parentId = $cat->parent_id ?? 'NULL';

            if ($cat->parent_id) {
                $parent = Category::find($cat->parent_id);
                $parentName = $parent ? json_decode($parent->name, true)['en'] : 'Unknown';
                $parentInfo = "{$parentId} ({$parentName})";
            } else {
                $parentInfo = "NULL (root)";
            }

            echo "\nCategory: {$nameEn}\n";
            echo "  Slug: {$cat->slug}\n";
            echo "  Parent: {$parentInfo}\n";
        }

        return $successCount === 4 && count($failedRows) === 0;

    } catch (\Throwable $e) {
        echo "ERROR: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
        return false;
    }
}

// =======================
// TEST 3: Verify Products
// =======================
function verifyProducts() {
    echo "\n=================================================================\n";
    echo "PRODUCT VERIFICATION (Already Imported)\n";
    echo "=================================================================\n\n";

    $products = Product::whereIn('sku', ['PRD-SAMPLE-001', 'PRD-SAMPLE-002', 'PRD-SAMPLE-003'])
        ->with(['categories', 'brand', 'tags'])
        ->get();

    if ($products->count() !== 3) {
        echo "ERROR: Expected 3 products, found {$products->count()}\n";
        return false;
    }

    foreach ($products as $product) {
        $nameData = json_decode($product->name, true);
        $nameEn = $nameData['en'] ?? 'N/A';
        $nameAr = $nameData['ar'] ?? 'N/A';

        echo "\n--- Product: {$product->sku} ---\n";
        echo "Name EN: {$nameEn}\n";
        echo "Name AR: {$nameAr}\n";
        echo "Item Type: {$product->item_type}\n";
        echo "Price: {$product->price}\n";
        echo "Quantity: " . ($product->quantity ?? 'NULL') . "\n";

        echo "Categories: ";
        if ($product->categories->count() > 0) {
            echo $product->categories->pluck('slug')->implode(', ');
        } else {
            echo "(none)";
        }
        echo "\n";

        echo "Brand: ";
        if ($product->brand) {
            echo $product->brand->slug;
        } else {
            echo "(none)";
        }
        echo "\n";

        echo "Tags: ";
        if ($product->tags->count() > 0) {
            echo $product->tags->pluck('slug')->implode(', ');
        } else {
            echo "(none)";
        }
        echo "\n";
    }

    return true;
}

// =======================
// RUN THREE TESTS
// =======================
$results = [
    'brand_run1' => false,
    'brand_run2' => false,
    'brand_run3' => false,
    'category_run1' => false,
    'category_run2' => false,
    'category_run3' => false,
    'products' => false,
];

// Run 1
$results['brand_run1'] = testBrandImport(1);
sleep(1);
$results['category_run1'] = testCategoryImport(1);
sleep(1);

// Run 2
$results['brand_run2'] = testBrandImport(2);
sleep(1);
$results['category_run2'] = testCategoryImport(2);
sleep(1);

// Run 3
$results['brand_run3'] = testBrandImport(3);
sleep(1);
$results['category_run3'] = testCategoryImport(3);

// Verify products
$results['products'] = verifyProducts();

// =======================
// FINAL SUMMARY
// =======================
echo "\n=================================================================\n";
echo "FINAL SUMMARY - THREE RUN VERIFICATION\n";
echo "=================================================================\n\n";

echo "Brand Import:\n";
echo "  Run 1: " . ($results['brand_run1'] ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Run 2: " . ($results['brand_run2'] ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Run 3: " . ($results['brand_run3'] ? '✓ PASS' : '✗ FAIL') . "\n";

echo "\nCategory Import:\n";
echo "  Run 1: " . ($results['category_run1'] ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Run 2: " . ($results['category_run2'] ? '✓ PASS' : '✗ FAIL') . "\n";
echo "  Run 3: " . ($results['category_run3'] ? '✓ PASS' : '✗ FAIL') . "\n";

echo "\nProduct Verification:\n";
echo "  Status: " . ($results['products'] ? '✓ PASS' : '✗ FAIL') . "\n";

$allPass = !in_array(false, $results, true);

echo "\n" . str_repeat("=", 65) . "\n";
if ($allPass) {
    echo "OVERALL STATUS: ✓ ALL TESTS PASSED\n";
    echo "PRODUCTION READY: YES\n";
} else {
    echo "OVERALL STATUS: ✗ SOME TESTS FAILED\n";
    echo "PRODUCTION READY: NO\n";
}
echo str_repeat("=", 65) . "\n";
