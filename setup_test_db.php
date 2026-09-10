<?php
// Setup test database for concurrency tests

try {
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306', 'root', '');
    echo 'Connected to MySQL' . PHP_EOL;

    $pdo->exec('DROP DATABASE IF EXISTS marvel_laravel_test');
    $pdo->exec('CREATE DATABASE marvel_laravel_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

    echo 'Database marvel_laravel_test created successfully' . PHP_EOL;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}
