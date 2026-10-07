<?php
// SCOPE PROBE: list in-scope routes from runtime inventory (read-only).
error_reporting(E_ERROR);
$inv = json_decode(file_get_contents(__DIR__.'/../storage/route-inventory.json'), true);
$scope = [];
foreach ($inv as $e) {
    $u = $e['uri'];
    $in = false; $bucket = '';
    if (strpos($u, '/api/v1/v1/') === 0) { $in = true; $bucket = 'double-v1'; }
    elseif (strpos($u, '/api/v1/admin/') === 0) { $in = true; $bucket = 'admin-single'; }
    elseif (strpos($u, '/api/v1/dashboard') === 0) { $in = true; $bucket = 'dashboard'; }
    elseif (strpos($u, '/api/v1/logs') === 0) { $in = true; $bucket = 'logs'; }
    elseif (strpos($u, '/api/v1/settings') === 0) { $in = true; $bucket = 'settings'; }
    elseif ($u === '/api/v1/enum-types') { $in = true; $bucket = 'enums'; }
    elseif (strpos($u, '/api/v1/broadcasting') === 0) { $in = true; $bucket = 'broadcast'; }
    elseif (strpos($u, '/api/v1/user/') === 0) { $in = true; $bucket = 'user'; }
    elseif (preg_match('#/checkout/(callback|error-callback|webhooks)|/track-order#', $u)) { $in = true; $bucket = 'system'; }
    if ($in) $scope[] = array_merge(['bucket' => $bucket], $e);
}
usort($scope, fn($a,$b)=>strcmp($a['method'].' '.$a['uri'], $b['method'].' '.$b['uri']));
$c = [];
foreach ($scope as $e) $c[$e['bucket']] = ($c[$e['bucket']] ?? 0) + 1;
echo "TOTAL: ".count($scope)."\n".json_encode($c, JSON_PRETTY_PRINT)."\n";
foreach ($scope as $e) {
    echo $e['bucket']." | ".$e['method']." ".$e['uri']." | ".(is_string($e['action'])?$e['action']:'Closure')." | ".implode(',', (array)$e['middleware'])."\n";
}
