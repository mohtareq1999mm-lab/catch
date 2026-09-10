<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';$kernel=$app->make(Illuminate\Contracts\Console\Kernel::class);$kernel->bootstrap();
use Illuminate\Support\Facades\DB;
$deleted=DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->delete();
echo "deleted media $deleted\n";
echo "media now ".DB::table('media')->where('model_type','Marvel\\Database\\Models\\Product')->count()."\n";
echo "jobs ".DB::table('jobs')->count()."\n";
