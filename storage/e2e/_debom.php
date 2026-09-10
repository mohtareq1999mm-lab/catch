<?php
$p = 'storage/e2e/_gate.php';
$b = file_get_contents($p);
if (substr($b, 0, 3) === "\xEF\xBB\xBF") {
    file_put_contents($p, substr($b, 3));
    echo "BOM stripped\n";
} else {
    echo "no BOM present\n";
}
