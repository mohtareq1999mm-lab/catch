<?php
error_reporting(E_ERROR);
$inv = json_decode(file_get_contents(__DIR__.'/../storage/route-inventory.json'), true);
function cls($e) {
    $mw = implode(' ', (array)$e['middleware']);
    $uri = $e['uri'];
    if (preg_match('#/webhook|/callback|/broadcasting/#i', $uri)) return 'system';
    if (preg_match('#^/api/v1/(admin|v1/admin)/#', $uri)) return 'admin';
    if (preg_match('#permission:#', $mw)) return 'admin';
    if (preg_match('#auth:sanctum#', $mw)) {
        if (preg_match('#throttle:admin#', $mw)) return 'admin';
        return 'general';
    }
    return 'general';
}
foreach ($inv as $e) {
    if (cls($e) === 'system') echo $e['method'].' '.$e['uri'].' | '.$e['action'].' | '.implode(',', (array)$e['middleware']).PHP_EOL;
}
echo "---- non-system callbacks/docs ----".PHP_EOL;
foreach ($inv as $e) {
    if (preg_match('#callback|webhook|broadcast|documentation|oauth#i', $e['uri']) && cls($e) !== 'system') echo $e['method'].' '.$e['uri'].' => '.cls($e).PHP_EOL;
}
