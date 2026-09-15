<?php

$files = [
    'D:/work/meem/database/database.sqlite',
    'D:/work/meem/storage/migrate_check.sqlite',
    'D:/work/meem/storage/w3-audit/w6q.sqlite',
];

foreach ($files as $file) {
    if (!file_exists($file)) {
        echo "MISSING: $file" . PHP_EOL;
        continue;
    }
    $pdo = new PDO('sqlite:' . $file);
    $products = (int) $pdo->query('SELECT COUNT(*) FROM products')->fetchColumn();
    $categories = (int) $pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    $brands = (int) $pdo->query('SELECT COUNT(*) FROM brands')->fetchColumn();
    echo basename($file) . " => products=$products categories=$categories brands=$brands" . PHP_EOL;
}
