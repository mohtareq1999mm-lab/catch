<?php
$envPath = __DIR__ . '/../.env';
$env = file_get_contents($envPath);
$changed = false;
if (preg_match('/^CACHE_DRIVER=redis/m', $env)) { $env = preg_replace('/^CACHE_DRIVER=redis/m', 'CACHE_DRIVER=file', $env); $changed=true; echo "CACHE_DRIVER -> file\n"; }
if (preg_match('/^SESSION_DRIVER=redis/m', $env)) { $env = preg_replace('/^SESSION_DRIVER=redis/m', 'SESSION_DRIVER=file', $env); $changed=true; echo "SESSION_DRIVER -> file\n"; }
if (preg_match('/^QUEUE_CONNECTION=database/m', $env)) { echo "QUEUE already database\n"; }
if ($changed) file_put_contents($envPath, $env);
echo "Done\n";
