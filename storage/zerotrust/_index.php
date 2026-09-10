<?php

error_reporting(E_ALL);
ini_set('display_errors', '1');

require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

// neutralize throttling for the harness (keeps auth + permission middleware active)
foreach (['api', 'public-api', 'admin', 'login', 'authenticated', 'content', 'sensitive', 'search', 'cart', 'orders'] as $limiter) {
    RateLimiter::for($limiter, fn ($request) => Limit::none());
}

function ev(string $line): void
{
    echo $line . PHP_EOL;
}

function record(string $id, bool $ok, string $detail = ''): void
{
    $GLOBALS['ztResults'][] = ['id' => $id, 'ok' => $ok, 'detail' => $detail];
}

function json($v)
{
    return json_decode($v, true);
}

function row(string $table, $id)
{
    return DB::table($table)->find($id);
}

function cacheKey(string $uri): string
{
    return md5('http://localhost' . $uri);
}

function ztIds(array $payload): array
{
    $candidate = $payload['data'] ?? null;
    if (is_array($candidate) && isset($candidate[0]) && is_array($candidate[0]) && isset($candidate[0]['id'])) {
        return array_column($candidate, 'id');
    }
    if (isset($payload[0]) && is_array($payload[0]) && isset($payload[0]['id'])) {
        return array_column($payload, 'id');
    }
    return [];
}

function snap(array $tables): array
{
    $out = [];
    foreach ($tables as $t) {
        if (str_starts_with($t, 'telescope_')) {
            continue;
        }
        $out[$t] = (int) DB::table($t)->count();
    }
    return $out;
}

function snapJson(array $tables): string
{
    return json_encode(snap($tables));
}

function http(string $method, string $uri, ?array $payload = null, ?string $token = null, ?string $lang = null): array
{
    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_LANG' => $lang ?? 'en',
        'CONTENT_TYPE' => 'application/json',
    ];
    $content = $payload !== null ? json_encode($payload) : null;
    $request = HttpRequest::create('http://localhost' . $uri, $method, [], [], [], $server, $content);
    if ($token) {
        $request->headers->set('Authorization', 'Bearer ' . $token);
    }
    $response = app(HttpKernel::class)->handle($request);
    app('auth')->forgetGuards();
    app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    return [$response->getStatusCode(), json_decode($response->getContent(), true) ?? []];
}

$GLOBALS['ztResults'] = [];
$GLOBALS['tables'] = array_map(
    fn ($t) => $t->name,
    DB::select('select table_name as name from information_schema.tables where table_schema = database()')
);

require __DIR__ . '/_reset.php';

$GLOBALS['ztStart'] = snap($GLOBALS['tables']);

require __DIR__ . '/_base.php';
require __DIR__ . '/_crud.php';
require __DIR__ . '/_public.php';
require __DIR__ . '/_cache.php';
require __DIR__ . '/_authz.php';
require __DIR__ . '/_valid.php';
require __DIR__ . '/_scenario.php';
require __DIR__ . '/_final.php';