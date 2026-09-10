<?php
/**
 * Queue normalization v2: CRLF-safe; verifies insertion per file.
 */

$targets = [
    realpath('packages/marvel/src/Listeners/CommissionRateUpdateListener.php'),
    realpath('packages/marvel/src/Listeners/MaintenanceNotification.php'),
];
$eventsDir = realpath('packages/marvel/src/Events');
foreach (glob($eventsDir . '/*.php') as $file) {
    $src = file_get_contents($file);
    if (!preg_match('/implements[^\n]*ShouldQueue/', $src)) continue;
    if (preg_match('/public\s+\$queue\s*=/', $src)) continue;
    $targets[] = $file;
}

$ok = 0; $failed = [];
foreach (array_unique(array_filter($targets)) as $file) {
    $src = file_get_contents($file);
    if (preg_match('/public\s+\$queue\s*=/', $src)) { echo "skip: " . basename($file) . "\n"; continue; }

    // Match class opening brace followed by EOL (CRLF or LF).
    if (!preg_match('/(class\s+\w+[^\{]*\{\r?\n)/', $src, $m, PREG_OFFSET_CAPTURE)) {
        $failed[] = basename($file) . ' (no class-brace match)';
        continue;
    }
    $insertAt = $m[1][1] + strlen($m[1][0]);
    $eol = str_contains(substr($src, 0, $insertAt), "\r\n") ? "\r\n" : "\n";
    $insert = "    public \$queue = 'meem-medium';" . $eol . $eol;
    $src = substr_replace($src, $insert, $insertAt, 0);

    file_put_contents($file, $src);

    // Verify
    $check = file_get_contents($file);
    if (str_contains($check, "public \$queue = 'meem-medium';")) { $ok++; echo "OK: " . basename($file) . "\n"; }
    else { $failed[] = basename($file); }
}
echo "ok={$ok} failed=" . count($failed) . "\n";
foreach ($failed as $f) echo "FAILED: {$f}\n";
