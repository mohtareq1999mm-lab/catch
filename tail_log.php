<?php
$path='storage/logs/laravel.log';
if(!file_exists($path)){ echo "no log\n"; exit; }
$lines=file($path);
$lines=array_slice($lines,-100);
foreach($lines as $l) echo $l;
echo "\n--- size ".filesize($path)." ---\n";
