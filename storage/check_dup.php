<?php
$env = file_get_contents(__DIR__ . '/../.env');
preg_match_all('/^MIX_PUSHER.*$/m', $env, $m);
print_r($m[0]);
