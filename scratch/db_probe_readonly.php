<?php
// READ-ONLY connectivity probe: connects with PDO and issues SELECT 1 + a
// schema-presence SELECT. Never writes, never migrates.
$host = getenv('DB_HOST') ?: '127.0.0.1';
$port = getenv('DB_PORT') ?: '3306';
$db = getenv('DB_DATABASE') ?: 'catch';
$user = getenv('DB_USERNAME') ?: 'root';
$pass = getenv('DB_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$db}",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    echo "CONNECTED {$db}\n";
    echo 'SELECT1=' . var_export($pdo->query('SELECT 1')->fetchColumn(), true) . "\n";
    foreach (['transactions', 'orders', 'settings'] as $t) {
        try {
            $c = $pdo->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            echo "{$t}={$c}\n";
        } catch (Throwable $e) {
            echo "{$t}=MISSING\n";
        }
    }
} catch (Throwable $e) {
    echo 'UNREACHABLE: ' . $e->getMessage() . "\n";
}
