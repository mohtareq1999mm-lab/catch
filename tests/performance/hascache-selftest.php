<?php

/**
 * Selftest for the HasCache tag-registry fallback on tag-less stores.
 * Cache-only: exercises remember()/flushTag() on the DEFAULT store (file here).
 * NO database access. Run:  php tests/performance/hascache-selftest.php
 */

require __DIR__ . '/../../vendor/autoload.php';

$app = require __DIR__ . '/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Cache;

$store = config('cache.default');
echo "cache.default = {$store}\n";

/**
 * Anonymous class exposing the protected trait helpers.
 * Mirrors how controllers/observers consume the trait.
 */
$subject = new class {
    use App\Traits\HasCache;

    public function pubRemember(string $tag, string $key, mixed $data, $ttl = null): mixed
    {
        return $this->remember($tag, $key, $data, $ttl);
    }

    public function pubFlushTag(string $tag): bool
    {
        return $this->flushTag($tag);
    }
};

$tag = 'SELFTEST_TAG';
$key = md5('selftest-' . microtime());

// 1) remember() closure path must NOT pre-execute; value cached (locale-suffixed key).
$value = $subject->pubRemember($tag, $key, fn () => 'HELLO-' . random_int(100, 999));
echo "[1] remember($tag, $key) => {$value}\n";

$cacheKey = $tag . ':' . $key . '|lang:' . app()->getLocale();
$cached = Cache::get($cacheKey);
echo '[2] direct key read (' . $cacheKey . ') => ' . var_export($cached, true) . "\n";

// 2) flushTag must now REALLY drop the entry on tag-less stores.
$flushed = $subject->pubFlushTag($tag);
$after = Cache::get($cacheKey);
echo '[3] flushTag => ' . var_export($flushed, true) . ' ; key after flush => ' . var_export($after, true) . "\n";

// 3) remember() again rebuilds and re-registers the key (self-healing registry).
$subject->pubRemember($tag, $key, 'ROUND2');
$again = Cache::get($cacheKey);
$subject->pubFlushTag($tag);
$gone2 = Cache::get($cacheKey);
echo '[4] round2 value => ' . var_export($again, true) . ' ; after 2nd flush => ' . var_export($gone2, true) . "\n";

// 5) LAZY-CLOSURE GUARANTEE (G2-F-01/G2-SHOW fix proof): the callback must run
//    exactly ONCE across a miss + cache hit with the same key.
$executions = 0;
$lazy = function () use (&$executions) {
    $executions++;

    return 'LAZY-' . $executions;
};
$first = $subject->pubRemember($tag, $key, $lazy);   // miss  -> executes
$second = $subject->pubRemember($tag, $key, $lazy);  // hit   -> must NOT execute
echo "[5] lazy run count => {$executions} ; miss={$first} hit={$second}\n";
$subject->pubFlushTag($tag);

// 6) LOCALE ISOLATION: the same tag+key built in another locale is a distinct
//    entry, and a single flushTag retires every locale variant.
app()->setLocale('en');
$en = $subject->pubRemember($tag, $key, 'EN-PAYLOAD');
app()->setLocale('ar');
$ar = $subject->pubRemember($tag, $key, 'AR-PAYLOAD');
$enRaw = Cache::get($tag . ':' . $key . '|lang:en');
$arRaw = Cache::get($tag . ':' . $key . '|lang:ar');
$subject->pubFlushTag($tag);
$enGone = Cache::get($tag . ':' . $key . '|lang:en');
$arGone = Cache::get($tag . ':' . $key . '|lang:ar');
echo "[6] en={$en} ar={$ar} raw_en=" . var_export($enRaw, true) . ' raw_ar=' . var_export($arRaw, true)
    . ' after_flush_en=' . var_export($enGone, true) . ' after_flush_ar=' . var_export($arGone, true) . "\n";

$pass = $cached === $value
    && $after === null
    && $again === 'ROUND2'
    && $gone2 === null
    && $executions === 1
    && $first === $second
    && $enRaw === 'EN-PAYLOAD'
    && $arRaw === 'AR-PAYLOAD'
    && $enGone === null
    && $arGone === null;

echo $pass ? "SELFTEST PASS (registry invalidation + lazy closure + locale isolation on '{$store}' store)\n"
           : "SELFTEST FAIL\n";
exit($pass ? 0 : 1);
