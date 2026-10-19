<?php
// Print the last 3 ERROR entries from laravel.log with short context.
$log = __DIR__ . '/../../logs/laravel.log';
if (!is_file($log)) {
    echo "no log", PHP_EOL;
    exit(1);
}
$lines = file($log, FILE_IGNORE_NEW_LINES);
$total = count($lines);
$offset = max(0, $total - 3000); // last ~3000 lines
$entries = [];
for ($i = $offset; $i < $total; $i++) {
    $t = $lines[$i];
    if (preg_match('#^\[[^]]*\]\s*(local|production)\.ERROR#', $t)) {
        $entries[] = ['ln' => $i, 'head' => $t];
    }
}
$keep = array_slice($entries, -3);
foreach ($keep as $e) {
    echo "--- LN", $e['ln'], " ---", PHP_EOL;
    echo substr($e['head'], 0, 500), PHP_EOL;
    for ($k = $e['ln'] + 1, $n = 0; $k < $total && $n < 10; $k++, $n++) {
        echo substr($lines[$k], 0, 200), PHP_EOL;
    }
}
