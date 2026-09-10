<?php
$dir = __DIR__.'/../tests/Feature/Tax';
if (is_dir($dir)) {
    $files = glob($dir.'/*');
    foreach ($files as $f) { if (is_file($f)) { unlink($f); echo "deleted $f\n"; } }
    rmdir($dir);
    echo "rmdir Tax\n";
}
