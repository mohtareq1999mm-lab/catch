<?php
// loghead3: match ERROR entries with bracketed timestamp prefix; report around a time window.
$log = __DIR__ . '/../../logs/laravel.log';
$lines = file($log, FILE_IGNORE_NEW_LINES);
$total = count($lines);
echo "TOTAL_LINES=$total", PHP_EOL;
$entries = [];
for ($i = 0; $i < $total; $i++) {
    if (preg_match('#^\[[^]]+\]\s*(local|production)\.(ERROR|CRITICAL|ALERT|EMERGENG)#', $lines[$i])) {
        $entries[] = $i;
    }
}
echo "MATCHES=", count($entries), PHP_EOL;
$tail3 = array_slice($entries, -3);
echo "TAIL3_LN=", implode(',', $tail3), PHP_EOL;
// print heads of last 3
foreach ($tail3 as $ln) {
    echo "--- LN$ln: ", mb_substr($lines[$ln], 0, 220), PHP_EOL;
    $n = 0;
    for ($k = $ln + 1; $k < $total && $n < 6; $k++, $n++) {
        $s = $lines[$k];
        if (preg_match('#^\[[^]]+\]#', $s)) {
            break;
        }
        echo mb_substr($s, 0, 200), PHP_EOL;
    }
}
