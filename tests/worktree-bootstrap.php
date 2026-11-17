<?php

/**
 * Worktree-only PHPUnit bootstrap (verification infrastructure, no app code).
 *
 * The worktree's vendor/ is a junction onto the shared composer install. PHP
 * resolves __DIR__ inside the shared autoload files to the real path of the
 * junction target, so the generated project classmap loads app/packages/tests
 * classes from the MAIN working tree and shadows this worktree's code (the
 * "worst-case pollution" documented in
 * docs/audits/warehouse-workflow-off-implementation.md). Composer itself must
 * never run against the shared vendor.
 *
 * This bootstrap re-points every non-vendor classmap entry and PSR-4 prefix
 * that lives in the shared tree at the same relative path inside THIS
 * worktree, so tests exercise this tree's code.
 *
 * It is inert unless explicitly passed via --bootstrap:
 *
 *   $env:DB_DATABASE='catch_verify'
 *   php vendor\phpunit\phpunit\phpunit --bootstrap tests\worktree-bootstrap.php tests\<File>.php
 */

$root = dirname(__DIR__);
$loader = require $root . '/vendor/autoload.php';

$sharedRoot = str_replace('\\', '/', dirname(realpath($root . '/vendor'))) . '/';
$worktreeRoot = str_replace('\\', '/', $root) . '/';

$isProjectPath = function (string $absolutePath) use ($sharedRoot): ?string {
    // Resolve /../ segments (classmap paths go through vendor/composer/../..)
    // against the shared tree. Returns the normalized absolute path or null.
    $realPath = realpath($absolutePath);

    if ($realPath === false) {
        return null;
    }

    $normalized = str_replace('\\', '/', $realPath);

    if (!str_starts_with($normalized, $sharedRoot)) {
        return null;
    }

    // Vendor packages stay on the shared install (identical for every tree).
    if (str_starts_with($normalized, $sharedRoot . 'vendor/')) {
        return null;
    }

    return $normalized;
};

$rebasedClassmap = [];
foreach ($loader->getClassMap() as $class => $path) {
    $normalizedPath = $isProjectPath($path);

    if ($normalizedPath === null) {
        continue;
    }

    $relative = substr($normalizedPath, strlen($sharedRoot));
    if (is_file($worktreeRoot . $relative)) {
        $rebasedClassmap[$class] = $worktreeRoot . $relative;
    }
}

if ($rebasedClassmap !== []) {
    // addClassMap() array-merges: these keys win over the shared entries.
    $loader->addClassMap($rebasedClassmap);
}

foreach ($loader->getPrefixesPsr4() as $prefix => $dirs) {
    $rebasedDirs = [];
    foreach ($dirs as $dir) {
        $normalizedDir = $isProjectPath($dir);

        if ($normalizedDir === null) {
            $rebasedDirs[] = $dir;
        } else {
            $rebasedDirs[] = $worktreeRoot . substr($normalizedDir, strlen($sharedRoot));
        }
    }

    if ($rebasedDirs !== $dirs) {
        $loader->setPsr4($prefix, $rebasedDirs);
    }
}
