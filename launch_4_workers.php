<?php
for($i=0;$i<3;$i++){
  $log="storage/logs/worker_$i.log";
  $cmd="php artisan queue:work --queue=meem-medium --stop-when-empty --timeout=600 --tries=3 --sleep=1 --verbose > $log 2>&1";
  if(strtoupper(substr(PHP_OS,0,3))==='WIN'){
    $cmd="start /B ".$cmd;
    pclose(popen($cmd,"r"));
    echo "launched worker $i\n";
  }
  sleep(1);
}
echo "launched 3 workers\n";
