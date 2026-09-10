<?php
$pdo = new PDO('sqlite:' . __DIR__ . '/w6q.sqlite');
echo 'jobs total: ' . $pdo->query('SELECT count(*) FROM jobs')->fetchColumn() . "\n";
foreach ($pdo->query('SELECT id, queue, substr(payload,1,90) p, attempts FROM jobs') as $r) {
    echo json_encode($r) . "\n";
}
echo 'activity: ' . $pdo->query('SELECT count(*) FROM activity_log')->fetchColumn() . "\n";
