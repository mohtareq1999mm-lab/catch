<?php

// Dev utility: runs artisan `migrate` against the isolated MySQL verification
// database (`catch_verify`) to validate migration SQL without touching the
// project database (`catch`).
// Usage: php scripts/verify_migrations.php [--fresh]
//
// (Previously used a throwaway SQLite file; removed during the project-wide
// MySQL standardization — SQLite masked real MySQL incompatibilities.)

putenv('DB_CONNECTION=mysql');
putenv('DB_DATABASE=catch_verify');
$_ENV['DB_CONNECTION'] = 'mysql';
$_ENV['DB_DATABASE'] = 'catch_verify';
$_SERVER['DB_CONNECTION'] = 'mysql';
$_SERVER['DB_DATABASE'] = 'catch_verify';

require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$args = ['--force' => true];
if (in_array('--fresh', $argv ?? [], true)) {
    $exit = Illuminate\Support\Facades\Artisan::call('migrate:fresh', $args);
} else {
    $exit = Illuminate\Support\Facades\Artisan::call('migrate', $args);
}
echo Illuminate\Support\Facades\Artisan::output();
exit($exit);
