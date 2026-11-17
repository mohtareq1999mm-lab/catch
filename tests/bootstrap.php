<?php

/*
|--------------------------------------------------------------------------
| Worktree test bootstrap
|--------------------------------------------------------------------------
|
| The shared vendor install (a junction) is autoload-optimized: composer's
| classmap and PSR-4 rules canonicalize their $baseDir through the junction
| to the MAIN working tree. Without a correction, a test run from a git
| worktree silently loads App\, Tests\ and Marvel\ classes from whichever
| branch the main tree currently sits on — not the code under test.
|
| This bootstrap keeps vendor untouched (composer must never run here) and
| prepends a worktree-rooted autoloader for every repo-owned namespace, so
| each branch's tests exercise that branch's code. Third-party vendor
| libraries stay served by composer's shared autoloader.
|
*/

require __DIR__ . '/../vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    foreach ([
        'App\\' => __DIR__ . '/../app/',
        'Tests\\' => __DIR__ . '/../tests/',
        'Database\\Factories\\' => __DIR__ . '/../database/factories/',
        'Database\\Seeders\\' => __DIR__ . '/../database/seeders/',
        'Marvel\\Stubs\\' => __DIR__ . '/../packages/marvel/stubs/',
        'Marvel\\' => __DIR__ . '/../packages/marvel/src/',
    ] as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $file = $dir . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';

            if (is_file($file)) {
                require $file;

                return;
            }
            // Class belongs to a repo-owned namespace but is absent in this
            // worktree: fall through to composer (status-quo resolution).
        }
    }
}, true, true);
