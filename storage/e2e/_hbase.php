<?php

declare(strict_types=1);

// =====================================================================
// FULL REAL-WORLD E2E PRODUCTION VALIDATION - shared harness base.
// Executes requests through the REAL HTTP kernel (full middleware stack:
// throttle -> auth:sanctum -> spatie permission -> controllers) against a
// dedicated MySQL audit database (chawkbazar_e2e_audit), REAL Redis cache,
// and the database queue connection. Fresh guards per request replicate
// true per-process HTTP isolation.
// =====================================================================

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../../vendor/autoload.php';
$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(ConsoleKernel::class)->bootstrap();

Auth::shouldUse('sanctum');
error_reporting(E_ALL);
ini_set('display_errors', '1');

// HARNESS OVERRIDE (not a product change): the closure matrix performs several
// hundred sequential requests in-process. Real limiter VALUES are separately
// proven live (RATE-001 proves throttle:sensitive enforces exactly 5/min).
// We raise only the volume limiters so runs test LOGIC, not throttling.
\Illuminate\Support\Facades\RateLimiter::for('admin', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(100000));
\Illuminate\Support\Facades\RateLimiter::for('authenticated', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(100000));
\Illuminate\Support\Facades\RateLimiter::for('public-api', fn ($request) => \Illuminate\Cache\RateLimiting\Limit::perMinute(100000));

$logPath = __DIR__ . '/e2e-evidence.log';
if (!isset($GLOBALS['__logCleared'])) {
    @file_put_contents($logPath, '');
    $GLOBALS['__logCleared'] = true;
}
$results = $GLOBALS['__results'] ?? [];
$ipCounter = $GLOBALS['__ipCounter'] ?? 0;

function ev(string $l): void
{
    global $logPath;
    @file_put_contents($logPath, $l . PHP_EOL, FILE_APPEND);
    echo $l . PHP_EOL;
}

function record(string $id, bool $ok, string $detail = ''): void
{
    global $results;
    $results[$id] = ['ok' => $ok, 'detail' => $detail];
    ev('RESULT  ' . $id . '  ' . ($ok ? 'PASS' : 'FAIL') . ($detail !== '' ? '  -> ' . $detail : ''));
}

function saveState(): void
{
    global $results, $ipCounter;
    $GLOBALS['__results'] = $results;
    $GLOBALS['__ipCounter'] = $ipCounter;
}

function http(string $method, string $uri, ?array $payload = null, ?string $token = null, ?string $lang = null): array
{
    return httpFull($method, $uri, $payload, [], $token, $lang);
}

/** Same as http() but pins REMOTE_ADDR (for rate-limiter accumulation proofs). */
function httpPinnedIp(string $method, string $uri, ?array $payload = null, ?string $token = null): array
{
    return httpFull($method, $uri, $payload, [], $token, null, '203.0.113.77');
}

/** Real multipart/file request through the kernel. $files: name => [tmpPath, originalName, mime] */
function httpFull(string $method, string $uri, ?array $payload = null, array $files = [], ?string $token = null, ?string $lang = null, ?string $pinIp = null): array
{
    global $ipCounter;
    $ipCounter++;
    app('auth')->forgetGuards();

    $server = [
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_HOST' => 'localhost',
        'REMOTE_ADDR' => $pinIp ?? ('127.0.0.' . (($ipCounter % 250) + 1)),
    ];
    if ($lang !== null) {
        // Project locale mechanism = custom `lang` header (CheckLangMiddleware).
        $server['HTTP_LANG'] = $lang;
    }
    if ($token !== null) {
        $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
    }

    $req = Request::create('http://localhost' . $uri, $method, $payload ?? [], [], [], $server);

    if ($files !== []) {
        // Use Illuminate\Http\UploadedFile (same class real requests produce);
        // raw Symfony instances fail the framework's `uploaded` rule.
        $req->files->replace(array_map(function ($f) {
            // Spec shapes: [path, orig, mime] for a scalar field, or
            // [[path,orig,mime], [path,orig,mime], ...] for an array field.
            if (isset($f[0]) && is_array($f[0])) {
                return array_map(fn ($spec) => new \Illuminate\Http\UploadedFile($spec[0], $spec[1], $spec[2], null, true), $f);
            }
            [$path, $original, $mime] = $f;
            return new \Illuminate\Http\UploadedFile($path, $original, $mime, null, true);
        }, $files));
    } elseif ($payload !== null) {
        $server['CONTENT_TYPE'] = 'application/json';
        $req->server->add(['CONTENT_TYPE' => 'application/json']);
        $req = Request::create('http://localhost' . $uri, $method, [], [], [], $server, json_encode($payload));
    }

    $kernel = app(HttpKernel::class);
    $response = $kernel->handle($req);
    $content = (string) $response->getContent();
    $json = json_decode($content, true);
    if (!is_array($json)) {
        // Non-JSON response: capture signature bytes + headers as evidence.
        $json = [
            '__nonJson' => true,
            '__status' => $response->getStatusCode(),
            '__contentType' => $response->headers->get('Content-Type'),
            '__disposition' => $response->headers->get('Content-Disposition'),
            '__length' => strlen((string) $content),
            '__head' => substr((string) $content, 0, 16),
        ];
    }
    $kernel->terminate($req, $response);

    return [$response->getStatusCode(), $json, $response];
}

function row(string $table, int $id): ?object
{
    return DB::table($table)->where('id', $id)->first();
}

function countRows(string $table, array $where = []): int
{
    $q = DB::table($table);
    foreach ($where as $k => $v) {
        $q->where($k, $v);
    }
    return (int) $q->count();
}
