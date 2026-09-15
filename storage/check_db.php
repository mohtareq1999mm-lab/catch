<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "DB_CONNECTION=" . config('database.default') . PHP_EOL;
echo "DB_DATABASE=" . config('database.connections.' . config('database.default') . '.database') . PHP_EOL;
try {
  $pdo = Illuminate\Support\Facades\DB::connection()->getPdo();
  echo "DB connected OK\n";
  echo "tables: " . implode(", ", Illuminate\Support\Facades\DB::select("SELECT name FROM sqlite_master WHERE type='table' LIMIT 5")) . "\n";
} catch (Throwable $e) {
  echo "DB failed: " . $e->getMessage() . "\n";
}
