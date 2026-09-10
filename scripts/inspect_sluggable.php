<?php
$projectRoot = dirname(__DIR__);
require $projectRoot . '/vendor/autoload.php';
$app = require_once $projectRoot . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Marvel\Database\Models\Category;

$cat = new Category();
$cfg = $cat->sluggable();
print_r($cfg);

echo "\n";
