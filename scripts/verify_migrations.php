<?php

// Dev utility: runs artisan `migrate` against a throwaway sqlite database to
// validate migration SQL without touching the configured database.
// Usage: php scripts/verify_migrations.php [--fresh]

$dbFile = __DIR__ . '/../storage/migrate_check.sqlite';

if (in_array('--fresh', $argv ?? [], true) && file_exists($dbFile)) {
    @unlink($dbFile);
}
if (!file_exists($dbFile)) {
    @touch($dbFile);
}

putenv('DB_CONNECTION=sqlite');
putenv('DB_DATABASE=' . $dbFile);
$_ENV['DB_CONNECTION'] = 'sqlite';
$_ENV['DB_DATABASE'] = $dbFile;
$_SERVER['DB_CONNECTION'] = 'sqlite';
$_SERVER['DB_DATABASE'] = $dbFile;

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$exit = Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
echo Illuminate\Support\Facades\Artisan::output();
exit($exit);
