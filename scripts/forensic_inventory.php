<?php
// Forensic rebuild: route inventory (runtime) + deep overlays (source-traced) -> spec + postman.
// READ-ONLY: never modifies app code. Unknown fields stay unknown, never invented.
error_reporting(E_ERROR);
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$routes = Illuminate\Support\Facades\Route::getRoutes();
$inv = [];
foreach ($routes as $r) {
    $uri = $r->uri();
    if (stripos($uri, 'api') !== 0) continue;
    if (stripos($uri, 'api/documentation') === 0 || stripos($uri, 'api/oauth2') === 0) {
        // keep swagger docs endpoints (reachable) but mark system
    }
    $methods = array_values(array_filter($r->methods(), fn($m) => $m !== 'HEAD' && $m !== 'OPTIONS'));
    $action = $r->getAction();
    $mw = array_values(array_filter((array)($action['middleware'] ?? [])));
    foreach ($methods as $m) {
        $inv[] = [
            'method' => $m,
            'uri' => '/' . $uri,
            'name' => $r->getName(),
            'action' => $action['controller'] ?? ($action['uses'] ?? 'Closure'),
            'middleware' => $mw,
        ];
    }
}
usort($inv, fn($a,$b) => strcmp($a['method'].' '.$a['uri'], $b['method'].' '.$b['uri']));
@mkdir(__DIR__.'/../storage', 0777, true);
file_put_contents(__DIR__.'/../storage/route-inventory.json', json_encode($inv, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo "inventory: ".count($inv).PHP_EOL;

// ---- classification ----
function classify($e) {
    $mw = implode(' ', (array)$e['middleware']);
    $uri = $e['uri'];
    if (preg_match('#/webhook|/callback|/broadcasting/#i', $uri)) return 'system';
    if (preg_match('#^/api/v1/(admin|v1/admin)/#', $uri)) return 'admin';
    if (preg_match('#permission:#', $mw)) return 'admin';
    if (preg_match('#auth:sanctum#', $mw)) {
        // Marvel admin group uses throttle:admin; customer uses throttle:authenticated/cart
        if (preg_match('#throttle:admin#', $mw)) return 'admin';
        return 'general';
    }
    return 'general';
}
$c = ['general'=>0,'admin'=>0,'system'=>0];
foreach ($inv as $e) $c[classify($e)]++;
echo json_encode($c).PHP_EOL;
