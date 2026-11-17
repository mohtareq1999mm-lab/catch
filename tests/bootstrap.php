<?php

/*
 * Worktree-local phpunit bootstrap.
 *
 * vendor/ is a junction to the shared composer install, and the Marvel
 * package resolves through it to the shared checkout (vendor/marvel/shop).
 * Marvel is a CLASSMAP package ("classmap": ["src"]), so a class added in
 * this checkout's packages/marvel is invisible to the shared autoloader
 * until a composer dump-autoload runs there.
 *
 * This bootstrap keeps the shared vendor untouched and prepends one
 * fallback autoloader: for Marvel classes it first looks in THIS
 * checkout's packages/marvel/src (standard namespace-to-path mapping).
 * Every hit loads this checkout's class; every miss falls through to the
 * shared composer classmap, so existing classes and classes with
 * divergent paths keep loading exactly as before.
 */

require __DIR__.'/../vendor/autoload.php';

spl_autoload_register(function (string $class): void {
    $prefix = 'Marvel\\';

    if (!str_starts_with($class, $prefix)) {
        return;
    }

    // The Marvel\\ namespace root maps to packages/marvel/src/; strip the
    // namespace prefix before converting to a path.
    $file = __DIR__.'/../packages/marvel/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';

    if (is_file($file)) {
        require $file;
    }
}, true, true);
