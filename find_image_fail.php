<?php
$log=file_get_contents('storage/logs/laravel.log');
$lines=explode("\n",$log);
$found=[];
foreach($lines as $i=>$line){
  if(strpos($line,'ImportProductImagesJob')!==false || strpos($line,'ERROR')!==false){
    $found[]=$i.":".substr($line,0,300);
    if(count($found)>20) break;
  }
}
echo implode("\n",array_slice($found,-20))."\n";
echo "\n--- last 2000 ---\n";
echo substr($log,-4000)."\n";
