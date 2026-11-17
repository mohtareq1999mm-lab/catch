<?php

/*
|--------------------------------------------------------------------------
| Worktree autoload remap
|--------------------------------------------------------------------------
|
| vendor/ is a junction to the shared Composer install created in the main
| working tree. PHP resolves the junction at include time, so the shared
| autoloader's $baseDir resolves to the MAIN tree: App\, Tests\ and Marvel\
| always load from the main working tree, and a run from any worktree
| silently executes main-tree code instead of the branch under test (the
| new guest surface case stayed invisible to its own suite until this).
|
| What this does — in-process only; composer never runs and the junction
| (and the shared install behind it) is never written to:
|
|  1. Load the shared vendor autoloader.
|  2. PREPEND a class loader that resolves App\, Tests\ and Marvel\ from
|     THIS directory first (the shared loader stays authoritative for all
|     other prefixes: vendor packages, helpers, dev deps).
|  3. Load the owning tree's .env when this directory has none — identical
|     to what a test run in the main tree loads today (APP_KEY encryption,
|     local MySQL credentials). A worktree-local .env, if present, wins.
*/

require_once __DIR__ . '/vendor/autoload.php';

$worktreeLoader = new \Composer\Autoload\ClassLoader();
$worktreeLoader->setPsr4('App\\', [__DIR__ . '/app']);
$worktreeLoader->setPsr4('Tests\\', [__DIR__ . '/tests']);
$worktreeLoader->setPsr4('Marvel\\', [__DIR__ . '/packages/marvel/src']);
$worktreeLoader->register(true); // prepend: this tree wins over the shared install

if (!is_file(__DIR__ . '/.env')) {
    $vendorAutoload = realpath(__DIR__ . '/vendor/autoload.php');

    if ($vendorAutoload !== false) {
        // <owning tree>/vendor/autoload.php -> <owning tree>/.env
        $owningTree = dirname(dirname($vendorAutoload));
        $envFile = $owningTree . '/.env';

        if (is_file($envFile)) {
            // Immutable: process env (phpunit.xml <env>, shell overrides such
            // as DB_DATABASE=catch_verify) always wins over file values.
            \Dotenv\Dotenv::createUnsafeImmutable($owningTree, basename($envFile))->safeLoad();
        }
    }
}
