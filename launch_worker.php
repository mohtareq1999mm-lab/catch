<?php
// Launch queue worker in background on Windows
$cmd = 'php artisan queue:work --queue=meem-medium --stop-when-empty --timeout=600 --tries=3 --sleep=1 --verbose > storage/logs/worker.log 2>&1';
echo "Launching: $cmd\n";
if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
    $cmd = 'start /B ' . $cmd;
    pclose(popen($cmd, 'r'));
    echo "Launched via start /B\n";
} else {
    $cmd = $cmd . ' &';
    exec($cmd);
    echo "Launched via &\n";
}
sleep(1);
echo "done launch\n";
if (file_exists('storage/logs/worker.log')) {
    echo "worker log exists size ".filesize('storage/logs/worker.log')."\n";
    echo substr(file_get_contents('storage/logs/worker.log'), -2000);
}
