<?php
/**
 * Queue normalization: add explicit meem-medium assignment to queued
 * listeners/events that relied on the implicit default queue.
 * Idempotent; skips files that already declare a queue.
 */

$targets = [
    realpath('packages/marvel/src/Listeners/CommissionRateUpdateListener.php'),
    realpath('packages/marvel/src/Listeners/MaintenanceNotification.php'),
];

// Queued events lacking an explicit queue
$eventsDir = realpath('packages/marvel/src/Events');
foreach (glob($eventsDir . '/*.php') as $file) {
    $src = file_get_contents($file);
    if (!preg_match('/implements\s+[^\n]*ShouldQueue/', $src)) continue;
    if (preg_match('/public\s+\$queue\s*=/', $src)) continue;
    $targets[] = $file;
}

$changed = 0;
foreach (array_unique($targets) as $file) {
    $src = file_get_contents($file);
    if (preg_match('/public\s+\$queue\s*=/', $src)) { echo "skip (has queue): " . basename($file) . "\n"; continue; }

    // Insert after the opening brace of the class statement.
    if (!preg_match('/^class\s+\w+[^\{]*\{/m', $src)) { echo "no class brace: " . basename($file) . "\n"; continue; }

    // Find the FIRST use-statement block end or class body start to place the property cleanly.
    // Simplest robust approach: insert immediately before the first method/property after class opening,
    // using the standard project style with a blank line.
    $src = preg_replace(
        '/^(class\s+\w+[^\{]*\{\n)/m',
        "$1    public \$queue = 'meem-medium';\n\n",
        $src,
        1
    );

    file_put_contents($file, $src);
    $changed++;
    echo "normalized: " . basename($file) . "\n";
}
echo "changed={$changed}\n";
