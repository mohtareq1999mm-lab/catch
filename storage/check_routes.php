<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();
$routes = app('router')->getRoutes();
foreach ($routes as $r) {
  $uri = $r->uri();
  if (str_contains($uri, 'products/import') || str_contains($uri, 'broadcasting/auth')) {
    echo $r->methods()[0] . " " . $uri . " -> " . $r->getName() . PHP_EOL;
  }
}
