<?php
$log=file_get_contents('storage/logs/laravel.log');
$lines=explode("\n",$log);
$lines=array_slice($lines,-50);
foreach($lines as $l) echo $l."\n";
