<?php
$log=file_get_contents('storage/logs/laravel.log');
$pos=strrpos($log,'ImportProductImagesJob');
if($pos!==false) echo substr($log,$pos-500,2000)."\n"; else echo "no ImportProductImagesJob in log\n";
$pos=strrpos($log,'dispatchImage');
if($pos!==false) echo substr($log,$pos-500,2000)."\n"; else echo "no dispatchImage\n";
$pos=strrpos($log,'product.import');
echo substr($log,strrpos($log,'product.import')-300,800)."\n";
