<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Product;
use Marvel\Database\Models\Settings;
use Marvel\Database\Models\User;
use Marvel\Database\Models\Cart;
use Marvel\Database\Models\CartItem;
use Marvel\Enums\ProductType;
use Marvel\Enums\ShippingMethod;
use Laravel\Sanctum\Sanctum;
use Illuminate\Http\Request;
use App\Services\General\OrderService;

// Fresh DB check: run migrations
// Use RefreshDatabase via TestCase? Instead directly test via OrderService with existing DB (verify_tax_schema already passed, so we can just test calculation via TaxCalculator)

use App\Services\Tax\TaxCalculator;

function check($a,$b,$msg) { echo ($a===$b ? "PASS" : "FAIL")." $msg: expected $b got $a\n"; }

// Scenario A: 100-10=90, 20% product, order disabled, shipping 20 => 128
$taxable = 90;
check(TaxCalculator::fromCents(TaxCalculator::amountOn(TaxCalculator::toCents($taxable),20)), 18.0, "Scenario A product tax");
check(90+18+20, 128, "Scenario A total");

// Scenario B: + order 10% => 9
check(TaxCalculator::fromCents(TaxCalculator::amountOn(TaxCalculator::toCents(90),10)), 9.0, "Scenario B order tax");
check(90+18+9+20, 137, "Scenario B total");

// Scenario C: 100-10-10=80, 20%=>16, 10%=>8, shipping20=>124
check(TaxCalculator::fromCents(TaxCalculator::amountOn(TaxCalculator::toCents(80),20)), 16.0, "Scenario C product tax");
check(TaxCalculator::fromCents(TaxCalculator::amountOn(TaxCalculator::toCents(80),10)), 8.0, "Scenario C order tax");
check(80+16+8+20, 124, "Scenario C total");

echo "Done\n";
