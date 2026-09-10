<?php
for($i=0;$i<4;$i++){
  $cmd="php artisan queue:work --queue=meem-medium --stop-when-empty --timeout=600 --tries=3 --sleep=1";
  if(strtoupper(substr(PHP_OS,0,3))==='WIN'){
    $cmd="start /B ".$cmd." > NUL 2>&1";
    pclose(popen($cmd,"r"));
    echo "launched $i\n";
  }
}
echo "done\n";
