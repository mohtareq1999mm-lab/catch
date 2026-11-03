<?php

/**
 * Concurrent-process locale isolation probe for HasCache on Redis.
 *
 * Usage:
 *   php tests/performance/redis-locale-concurrency.php --orchestrate [iterations] [tag]
 *   php tests/performance/redis-locale-concurrency.php <locale> <token> <iterations> <tag>
 *
 * Worker mode writes only its own token under its own locale, then reads the
 * same tag+key back. A shared (non-locale) key would surface another process's
 * token and be reported as FAIL.
 *
 * Orchestrate mode runs 2 ar + 2 en workers concurrently via proc_open, prints
 * their verdicts, then dumps the physical Redis keys for one probe key so the
 * per-locale cardinality is visible (expected: exactly 2 keys, one per locale)
 * and flushes the probe tag.
 *
 * The script pins REDIS_CACHE_DB=3 and audit_* prefixes so it can never touch
 * real cache keys.
 */

// Isolated audit environment (never touches the app's real cache config).
// putenv() runs before bootstrap so .env cannot override it (Dotenv is immutable).
putenv('CACHE_DRIVER=redis');
putenv('REDIS_CLIENT=predis');
putenv('REDIS_CACHE_DB=3');
putenv('CACHE_PREFIX=audit_cache');
putenv('REDIS_PREFIX=audit_');

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Traits\HasCache;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Redis;

if (!Cache::getStore() instanceof \Illuminate\Cache\RedisStore) {
    fwrite(STDERR, 'SKIP: cache store is not Redis (is Redis reachable on 127.0.0.1:6379?)' . PHP_EOL);
    exit(2);
}

$mode = $argv[1] ?? 'ar';

if ($mode === '--orchestrate') {
    $iterations = (int) ($argv[2] ?? 60);
    $tag = $argv[3] ?? 'audit_conc_tag';

    // Each pair shares one base key across locales: ar and en race for the same
    // logical entry, so a missing locale dimension in the key shows up as one
    // locale reading the other's token. Same-locale workers use different slots
    // so they cannot shadow each other (that would be normal cache behavior).
    $runs = [['ar', 'ar-0', '0'], ['en', 'en-0', '0'], ['ar', 'ar-1', '1'], ['en', 'en-1', '1']];

    $workers = [];

    foreach ($runs as [$locale, $token, $slot]) {
        $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . "redis_conc_{$locale}_{$token}.log";
        @unlink($log);

        $command = [PHP_BINARY, __FILE__, $locale, $token, (string) $iterations, $tag, $slot];
        $process = proc_open($command, [1 => ['file', $log, 'w'], 2 => ['file', $log, 'a']], $pipes);

        if (!is_resource($process)) {
            fwrite(STDERR, "SPAWN FAILED locale={$locale} token={$token}" . PHP_EOL);
            exit(1);
        }

        $workers[] = [$process, $log, $locale, $token];
    }

    $failed = 0;

    foreach ($workers as [$process, $log, $locale, $token]) {
        $exitCode = proc_close($process);
        $output = trim((string) @file_get_contents($log));

        echo ($output !== '' ? $output : "NO OUTPUT locale={$locale} token={$token}") . PHP_EOL;

        if ($exitCode !== 0) {
            $failed++;
        }

        @unlink($log);
    }

    $probe = '*conc-key-0-1|lang:*';
    $keys = Redis::connection('cache')->keys($probe);
    sort($keys);
    echo 'probe=' . $probe . ' keys=' . count($keys) . ' [' . implode(', ', $keys) . ']' . PHP_EOL;

    Cache::tags([$tag])->flush();

    echo 'after flush keys=' . count(Redis::connection('cache')->keys($probe)) . PHP_EOL;

    echo $failed === 0 && count($keys) === 2 ? 'CONCURRENCY PASS' . PHP_EOL : 'CONCURRENCY FAIL' . PHP_EOL;

    exit($failed === 0 && count($keys) === 2 ? 0 : 1);
}

$locale = $mode;
$token = $argv[2] ?? 'TOKEN';
$iterations = (int) ($argv[3] ?? 50);
$tag = $argv[4] ?? 'audit_conc_tag';
$slot = $argv[5] ?? '0';

$cacher = new class {
    use HasCache;

    public function put(string $tag, string $key, mixed $data): mixed
    {
        return $this->remember($tag, $key, $data);
    }
};

App::setLocale($locale);

$fail = 0;

for ($i = 1; $i <= $iterations; $i++) {
    $key = 'conc-key-' . $slot . '-' . $i;

    $cacher->put($tag, $key, fn () => $token);
    usleep(random_int(200, 1500));

    try {
        $read = $cacher->put($tag, $key, fn () => throw new RuntimeException('miss'));
    } catch (RuntimeException $e) {
        echo "FAIL {$locale} iter={$i} cache miss" . PHP_EOL;
        $fail++;

        continue;
    }

    if ($read !== $token) {
        echo "FAIL {$locale} iter={$i} expected={$token} got=" . var_export($read, true) . PHP_EOL;
        $fail++;
    }
}

echo ($fail === 0 ? 'OK' : 'FAILED') . " locale={$locale} iterations={$iterations} failures={$fail} pid=" . getmypid() . PHP_EOL;

exit($fail === 0 ? 0 : 1);
