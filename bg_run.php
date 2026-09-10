<?php
set_time_limit(0);
ini_set('memory_limit','1024M');
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Marvel\Jobs\ImportProductsJob;
$id = $argv[1] ?? 9;
echo "bg run import $id\n";
$job=new ImportProductsJob((int)$id);
$job->handle();
echo "done\n";
