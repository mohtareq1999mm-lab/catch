<?php
$envPath = __DIR__ . '/../.env';
$env = file_get_contents($envPath);
$env = preg_replace('/^DB_CONNECTION=.*$/m', 'DB_CONNECTION=sqlite', $env);
$env = preg_replace('/^DB_DATABASE=.*$/m', 'DB_DATABASE=' . __DIR__ . '/../database/database.sqlite', $env);
// Ensure file uses forward slashes for Windows
$env = str_replace('D:\work\meem', 'D:/work/meem', $env);
// Remove host/port/username/password lines or keep but they are ignored for sqlite
if (!file_exists(__DIR__ . '/../database/database.sqlite')) { touch(__DIR__ . '/../database/database.sqlite'); echo "Created sqlite file\n"; }
file_put_contents($envPath, $env);
echo "Fixed DB to sqlite\n";
echo file_get_contents($envPath) . "\n";
