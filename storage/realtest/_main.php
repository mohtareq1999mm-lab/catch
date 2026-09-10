<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

// The spatie `permission:` middleware resolves the user via the default guard.
// The PHPUnit suite achieves this through Sanctum::actingAs() which calls
// Auth::shouldUse('sanctum'). We replicate exactly that so the permission
// middleware can resolve the real bearer-token user (real tokens, real DB).
Auth::shouldUse('sanctum');

error_reporting(E_ALL);
ini_set('display_errors', '1');

$logPath = __DIR__ . '/realworld-evidence.log';
@file_put_contents($logPath, '');
$results = [];
$ipCounter = 0;

function ev(string $l): void
{
    global $logPath;
    @file_put_contents($logPath, $l . PHP_EOL, FILE_APPEND);
    echo $l . PHP_EOL;
}

function record(string $id, bool $ok, string $detail = ''): void
{
    global $results;
    $results[$id] = $ok;
    ev('RESULT  ' . $id . '  ' . ($ok ? 'PASS' : 'FAIL') . ($detail !== '' ? '  -> ' . $detail : ''));
}

function http(string $method, string $uri, ?array $payload = null, ?string $token = null, ?string $lang = null): array
{
    global $ipCounter;
    $ipCounter++;
    // Fresh auth guard per request: guards are singletons and would otherwise
    // cache the first request's user across kernel.handle() calls (in-process
    // request pipelining is NOT the same as real HTTP where each request is a
    // fresh process). forgetGuards() restores true request isolation.
    app('auth')->forgetGuards();
    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'CONTENT_TYPE' => 'application/json',
        'HTTP_HOST' => 'localhost',
        'REMOTE_ADDR' => '127.0.0.' . (($ipCounter % 250) + 1),
    ];
    if ($lang !== null) {
        $server['HTTP_ACCEPT_LANGUAGE'] = $lang;
        $server['HTTP_LANG'] = $lang;
    }
    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }
    $content = $payload !== null ? json_encode($payload, JSON_UNESCAPED_UNICODE) : null;
    $req = HttpRequest::create($uri, $method, [], [], [], $server, $content);
    $res = app(HttpKernel::class)->handle($req);
    app('auth')->forgetGuards();
    return [$res->getStatusCode(), json_decode($res->getContent(), true) ?: [], $res->getContent()];
}

function snap(array $tables): array
{
    $out = [];
    foreach ($tables as $t) {
        $out[$t] = DB::table($t)->count();
    }
    return $out;
}

function row(string $table, int $id): ?object
{
    return DB::table($table)->where('id', $id)->first();
}

function cacheKey(string $uri): string
{
    return md5(url($uri));
}

// ============ PHASES ============
require __DIR__ . '/_setup.php';    // env proof + baseline + users + types
require __DIR__ . '/_cp.php';       // content pages CRUD + visibility
require __DIR__ . '/_types.php';    // section types + settings
require __DIR__ . '/_sections.php'; // sections, attach, reorder, toggle, update, delete
require __DIR__ . '/_public.php';   // public endpoints, endpoint gen, translations
require __DIR__ . '/_cache.php';    // cache + isolation + observers
require __DIR__ . '/_n1.php';       // N+1 / query counts
require __DIR__ . '/_authz.php';    // authorization matrix
require __DIR__ . '/_valid.php';    // validation + zero mutation
require __DIR__ . '/_final.php';    // queue, integrity, accounting, summary

// ============ SUMMARY ============
$pass = count(array_filter($results, fn ($v) => $v === true));
$fail = count(array_filter($results, fn ($v) => $v === false));
ev('');
ev('=================================================================');
ev('SUMMARY — total checks: ' . ($pass + $fail) . '  PASS: ' . $pass . '  FAIL: ' . $fail);
ev('Evidence written to: ' . realpath($logPath));