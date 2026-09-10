<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;

echo "========================================\n";
echo "DATABASE CONNECTION\n";
echo "========================================\n";
$config = config('database.connections.' . config('database.default'));
echo "Driver: " . config('database.default') . "\n";
echo "Host: " . ($config['host'] ?? $config['url'] ?? 'N/A') . "\n";
echo "Port: " . ($config['port'] ?? 'N/A') . "\n";
echo "Database: " . ($config['database'] ?? 'N/A') . "\n";
echo "Username: " . ($config['username'] ?? 'N/A') . "\n";
echo "Environment: " . app()->environment() . "\n";
echo "========================================\n";

$files = [
    'brands' => 'packages/marvel/resources/brands/brand-import-sample.xlsx',
    'categories' => 'packages/marvel/resources/categories/category-import-sample.xlsx',
    'products' => 'packages/marvel/resources/products/product-import-sample.xlsx',
];

foreach ($files as $type => $path) {
    $full = __DIR__ . '/' . $path;
    echo "\n=== $type: $path ===\n";
    if (!file_exists($full)) {
        echo "NOT FOUND\n";
        continue;
    }
    echo "Exists: Yes, Size: " . filesize($full) . " bytes\n";
    try {
        $spreadsheet = IOFactory::load($full);
        echo "Sheets: " . implode(', ', $spreadsheet->getSheetNames()) . "\n";
        foreach ($spreadsheet->getAllSheets() as $sheet) {
            $title = $sheet->getTitle();
            $highestRow = $sheet->getHighestRow();
            $highestCol = $sheet->getHighestColumn();
            echo " Sheet: $title, Rows: $highestRow, Cols: $highestCol\n";
            $headers = [];
            for ($col = 'A'; $col <= $highestCol; $col++) {
                $val = $sheet->getCell($col . '1')->getValue();
                if ($val) $headers[] = trim($val);
            }
            echo "  Headers: " . implode(' | ', $headers) . "\n";
            // First 3 data rows
            for ($row = 2; $row <= min(4, $highestRow); $row++) {
                $rowData = [];
                for ($col = 'A'; $col <= $highestCol; $col++) {
                    $val = $sheet->getCell($col . $row)->getValue();
                    $rowData[] = $val ?? 'NULL';
                }
                echo "  Row $row: " . implode(' | ', $rowData) . "\n";
            }
        }
    } catch (Exception $e) {
        echo "Error: " . $e->getMessage() . "\n";
    }
}

echo "\n=== DATABASE BEFORE SNAPSHOT ===\n";
try {
    $counts = [
        'categories' => DB::table('categories')->count(),
        'brands' => DB::table('brands')->count(),
        'products' => DB::table('products')->count(),
        'product_variants' => DB::table('product_variants')->count(),
        'imports' => DB::table('imports')->count(),
    ];
    foreach ($counts as $k => $v) {
        echo "$k: $v\n";
    }
    // Check existing SKUs
    $skus = DB::table('products')->pluck('sku')->filter()->take(10);
    echo "Sample SKUs: " . implode(', ', $skus->toArray()) . "\n";
} catch (Exception $e) {
    echo "DB Error: " . $e->getMessage() . "\n";
}

echo "\n=== IMPORT ARCHITECTURE ===\n";
$importers = [
    'Marvel\Imports\BrandsImport',
    'Marvel\Imports\CategoriesImport',
    'Marvel\Imports\ProductsImport',
    'Marvel\Services\Import\BrandImportService',
    'Marvel\Services\Import\CategoryImportService',
    'Marvel\Services\Import\ProductImportService',
];
foreach ($importers as $cls) {
    echo "$cls: " . (class_exists($cls) ? "EXISTS" : "NOT FOUND") . "\n";
}

echo "\nDone\n";
