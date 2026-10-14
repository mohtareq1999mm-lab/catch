<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = Illuminate\Support\Facades\DB::connection()->getDatabaseName();
echo 'DB='.$db.PHP_EOL;
foreach (['product_locations', 'inventory_operations'] as $t) {
    try {
        $cols = Illuminate\Support\Facades\DB::select("SHOW COLUMNS FROM {$t}");
        echo "== {$t} ==".PHP_EOL;
        foreach ($cols as $c) {
            echo '  '.$c->Field.' | '.$c->Type.' | null='.($c->Null === 'YES' ? 'Y' : 'N').' | key='.$c->Key.' | default='.var_export($c->Default, true).PHP_EOL;
        }
        $idx = Illuminate\Support\Facades\DB::select("SHOW INDEX FROM {$t}");
        foreach ($idx as $i) {
            echo '  idx '.$i->Key_name.'('.$i->Column_name.') '.($i->Non_unique == 0 ? 'UNIQUE' : 'idx').PHP_EOL;
        }
    } catch (Throwable $e) {
        echo "== {$t} MISSING/ERROR: ".$e->getMessage().PHP_EOL;
    }
}
try {
    $m = Illuminate\Support\Facades\DB::table('migrations')->where('migration', 'like', '%variant%')->pluck('migration')->all();
    echo 'variant migrations recorded: '.json_encode($m).PHP_EOL;
    echo 'migration rows total: '.Illuminate\Support\Facades\DB::table('migrations')->count().PHP_EOL;
} catch (Throwable $e) {
    echo 'migrations table unreadable: '.$e->getMessage().PHP_EOL;
}
