<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$rows=DB::select('SELECT * FROM information_schema.INNODB_TRX');
foreach($rows as $r){ echo json_encode((array)$r, JSON_PRETTY_PRINT)."\n"; }
$locks=DB::select('SELECT * FROM information_schema.INNODB_LOCKS');
echo "locks:".count($locks)."\n";
foreach($locks as $l) echo json_encode((array)$l)."\n";
