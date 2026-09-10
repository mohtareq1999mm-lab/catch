<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\Cache;
use Illuminate\Http\Request;
use App\Services\Currency\CurrencyService;
use App\Services\Currency\UserCurrencyPreferenceService;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Schema;

// Use in-memory SQLite for quick test? But we need DB. We'll just test conversion logic.

$service = app(CurrencyService::class);
$pref = app(UserCurrencyPreferenceService::class);

// Simulate request with guest cookie SAR
$request = Request::create('/test', 'GET');
$request->cookies->set('guest_currency', 'SAR');
app()->instance('request', $request);
app()->forgetInstance(CurrencyService::class);
$service2 = app(CurrencyService::class);
echo "Effective code with SAR cookie: " . $service2->getEffectiveCode() . "\n";
echo "Catalog code: " . $service2->getCatalogCode() . "\n";
echo "Convert 100 USD to SAR: " . $service2->convertPrice(100, 'USD', 'SAR') . "\n";

// Test BrandProductResource conversion
$brand = new Brand(['name' => ['en' => 'Debug Brand'], 'slug' => 'debug-brand', 'status' => 1]);
$brand->id = 1;
$product = new Product(['name' => ['en' => 'Debug Product'], 'slug' => 'debug-product', 'price' => 100, 'status' => true, 'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0]);
$product->setAttribute('current_price', 100);
$product->setRelation('media', collect());

// Mock resource
$resource = new App\Http\Resources\Brand\BrandProductResource($product);
$array = $resource->toArray($request);
echo "BrandProductResource price: " . json_encode($array['price']) . "\n";
echo "Currency: " . json_encode($array['currency']) . "\n";
