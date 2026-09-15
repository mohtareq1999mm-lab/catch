<?php
$log = file_get_contents(__DIR__ . '/../storage/logs/laravel.log');
$lines = explode("\n", $log);
$matched = array_filter($lines, fn($l)=> str_contains($l, 'product-export') || str_contains($l, 'file-operation.event'));
foreach (array_slice($matched, -100) as $l) echo $l . "\n";
echo "---\n";
$imports = Illuminate\Support\Facades\DB::table('imports')->whereIn('id',[6,7,8])->get();
