<?php
require __DIR__ . '/vendor/autoload.php';
$app = require __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Http\Request;
use App\Http\Requests\SelectCurrencyRequest;
use Illuminate\Support\Facades\Validator;
use Marvel\Database\Models\Settings;
use App\Models\Currency;
use Illuminate\Support\Facades\Schema;

// Setup minimal DB for validation test
echo "=== Testing SelectCurrencyRequest JSON handling ===\n";

$testPayload = ['currency_code' => 'AED'];
$jsonRequest = Request::create('/api/v1/general/currencies/select', 'POST', [], [], [], ['CONTENT_TYPE' => 'application/json'], json_encode($testPayload));
$jsonRequest->headers->set('Content-Type', 'application/json');
$jsonRequest->headers->set('Accept', 'application/json');

echo "JSON Request content: " . $jsonRequest->getContent() . "\n";
echo "JSON Request input currency_code: " . var_export($jsonRequest->input('currency_code'), true) . "\n";
echo "JSON Request all(): " . json_encode($jsonRequest->all()) . "\n";

// Simulate FormData request
$formRequest = Request::create('/api/v1/general/currencies/select', 'POST', $testPayload, [], [], ['CONTENT_TYPE' => 'multipart/form-data']);
$formRequest->headers->set('Content-Type', 'multipart/form-data');
echo "\nFormData Request input currency_code: " . var_export($formRequest->input('currency_code'), true) . "\n";
echo "FormData Request all(): " . json_encode($formRequest->all()) . "\n";

// Test validation via SelectCurrencyRequest rules
$rules = (new SelectCurrencyRequest())->rules();
echo "\nRules: " . json_encode($rules) . "\n";

// Try validating JSON request data via Validator
$validatorJson = Validator::make($jsonRequest->all(), $rules);
echo "\nJSON validator passes? " . ($validatorJson->passes() ? 'yes' : 'no') . "\n";
if ($validatorJson->fails()) {
    echo "JSON validator errors: " . json_encode($validatorJson->errors()->toArray()) . "\n";
}

$validatorForm = Validator::make($formRequest->all(), $rules);
echo "\nFormData validator passes? " . ($validatorForm->passes() ? 'yes' : 'no') . "\n";
if ($validatorForm->fails()) {
    echo "FormData validator errors: " . json_encode($validatorForm->errors()->toArray()) . "\n";
}

// Check Request creation with Symfony's json handling
echo "\n=== Request->json() test ===\n";
echo "JSON request json() all: " . json_encode($jsonRequest->json()->all()) . "\n";
echo "Form request json() all: " . json_encode($formRequest->json()->all()) . "\n";

// Check if Illuminate Request properly decodes JSON when Content-Type is application/json
// Laravel's Request uses Symfony's Request, which parses JSON only if content-type is json.
// Let's see what $request->all() does: it merges query, request, and json.
// For JSON, it should include json data if Content-Type matches.

echo "\nDone\n";
