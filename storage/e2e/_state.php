<?php

// Shared cross-script state (tokens) - written by _e1, read by later phases.
$stateFile = __DIR__ . '/state.json';
if (!isset($GLOBALS['adminToken'])) {
    if (file_exists($stateFile)) {
        $st = json_decode((string) file_get_contents($stateFile), true) ?: [];
        $GLOBALS['adminToken'] = $st['adminToken'] ?? null;
        $GLOBALS['customerToken'] = $st['customerToken'] ?? null;
    }
}
if (isset($GLOBALS['adminToken'])) {
    file_put_contents($stateFile, json_encode([
        'adminToken' => $GLOBALS['adminToken'],
        'customerToken' => $GLOBALS['customerToken'],
    ]));
}
