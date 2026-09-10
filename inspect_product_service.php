<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Test building product data with one row
$service = new \Marvel\Services\Import\ProductImportService(null);
$ref = new ReflectionClass($service);
$m = $ref->getMethod('buildProductData');
$m->setAccessible(true);
$row = [
  'sku'=>'TEST-001',
  'name_en'=>'Test Product',
  'name_ar'=>'اختبار',
  'description_en'=>'desc',
  'description_ar'=>'وصف',
  'price'=>'10.5',
  'product_type'=>'simple',
  'item_type'=>'PHYSICAL',
  'quantity'=>'5',
  'status'=>'publish',
  'in_stock'=>'1',
];
$data = $m->invoke($service, $row);
echo json_encode($data, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE)."\n";

// Check Product fillable vs what buildProductData produces
echo "\n--- validateNumeric etc ---\n";
$m2 = $ref->getMethod('validateNumeric');
$m2->setAccessible(true);
try {
  echo $m2->invoke($service, 'abc', 'price', true) . "\n";
} catch(Exception $e){ echo "validate error: ".$e->getMessage()."\n";}

// Check categories sync logic
echo "\n--- Inspect product category handling ---\n";
$src = file_get_contents('packages/marvel/src/Services/Import/ProductImportService.php');
echo substr($src, strpos($src,'protected function buildProductData'), 2500);
