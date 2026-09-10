<?php
$log=file_get_contents('storage/logs/laravel.log');
$lines=explode("\n",$log);
$errs=[];
foreach($lines as $i=>$line){
  if(stripos($line,'ERROR')!==false || stripos($line,'Exception')!==false || stripos($line,'failed')!==false){
    $errs[] = ($i+1).": ".$line;
  }
}
echo implode("\n", array_slice($errs,-30))."\n";
echo "\n--- last 500 chars ---\n";
echo substr($log,-3000)."\n";
