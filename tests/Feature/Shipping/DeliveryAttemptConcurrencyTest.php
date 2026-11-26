<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * MySQL-only proof: two separate OS processes (separate connections) race to
 * record the first attempt with max_attempts=1. Exactly one may commit.
 */
class DeliveryAttemptConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Delivery attempt concurrency proof requires MySQL.');
        }
    }

    public function test_two_transactions_cannot_exceed_configured_maximum(): void
    {
        $tag = 'DA' . strtoupper(bin2hex(random_bytes(4)));
        $pdo = $this->createIndependentConnection();
        $userId = null;
        $representativeId = null;
        $shipmentId = null;
        $orderId = null;
        $assignmentId = null;

        try {
            [$userId, $representativeId, $shipmentId, $orderId, $assignmentId] = $this->insertCommittedFixture($pdo, $tag);

            $probe = dirname(__DIR__, 2) . '/Concurrency/delivery_attempt_probe.php';
            $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $processes = [];

            foreach (range(1, 2) as $index) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, $probe, (string) $assignmentId, (string) $representativeId],
                    $descriptors,
                    $pipes
                );

                $this->assertIsResource($process, "Probe {$index} could not be started.");
                $processes[] = ['process' => $process, 'pipes' => $pipes];
            }

            $results = [];
            foreach ($processes as $running) {
                $stdout = stream_get_contents($running['pipes'][1]);
                $stderr = stream_get_contents($running['pipes'][2]);
                fclose($running['pipes'][1]);
                fclose($running['pipes'][2]);
                proc_close($running['process']);

                $results[] = [
                    'stdout' => trim((string) $stdout),
                    'stderr' => trim((string) $stderr),
                ];
            }

            $successes = 0;
            $rejections = [];
            foreach ($results as $result) {
                $row = json_decode($result['stdout'], true);
                if (is_array($row) && ($row['ok'] ?? false) === true) {
                    $successes++;
                    continue;
                }

                $rejections[] = $row['message'] ?? ($result['stderr'] !== '' ? $result['stderr'] : $result['stdout']);
            }

            $attemptCount = (int) $pdo->query(
                'SELECT COUNT(*) FROM shipment_delivery_attempts WHERE shipment_id = ' . (int) $shipmentId
            )->fetchColumn();

            $this->assertSame(1, $successes, 'Exactly one concurrent transaction may record an attempt. ' . json_encode($results));
            $this->assertSame(1, $attemptCount, 'The shipment must never exceed max_attempts=1.');
            $this->assertContains(
                __('shipping.delivery_attempt.max_exceeded', ['max' => 1]),
                $rejections,
                'The losing transaction must be rejected by the configured maximum.'
            );
        } finally {
            $this->cleanupCommittedFixture($pdo, $shipmentId, $assignmentId, $representativeId, $orderId, $userId);
        }
    }

    /** @return array{int,int,int,int,int} */
    private function insertCommittedFixture(\PDO $pdo, string $tag): array
    {
        $password = bcrypt('secret');
        $now = date('Y-m-d H:i:s');

        $statement = $pdo->prepare(
            'INSERT INTO users (name, email, password, type, is_active, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, 1, ?, ?)'
        );
        $statement->execute(["Rep {$tag}", "rep-{$tag}@example.test", $password, 'user', $now, $now]);
        $userId = (int) $pdo->lastInsertId();

        // The canonical order row requires the default flow and its initial
        // status; both are seeded by the flow migrations, not by the test.
        $flowId = (int) $pdo->query("SELECT id FROM order_flows WHERE code = 'local' LIMIT 1")->fetchColumn();
        $statusId = (int) $pdo->query("SELECT id FROM order_statuses WHERE code = 'pending' LIMIT 1")->fetchColumn();
        $this->assertGreaterThan(0, $flowId, 'Default local order flow must be seeded.');
        $this->assertGreaterThan(0, $statusId, 'Pending order status must be seeded.');

        $statement = $pdo->prepare(
            'INSERT INTO orders (name, user_phone, user_email, address, total_price, price, flow_id, current_status_id, tracking_number, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([
            "Order {$tag}",
            '01000000000',
            'guest-' . strtolower($tag) . '@example.test',
            json_encode(['city' => 'Cairo'], JSON_THROW_ON_ERROR),
            100,
            100,
            $flowId,
            $statusId,
            "{$tag}-ORDER",
            $now,
            $now,
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare(
            'INSERT INTO shipments (uuid, order_id, tracking_number, status, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([Str::uuid()->toString(), $orderId, "{$tag}-SHIP", 'pending', $now, $now]);
        $shipmentId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare(
            'INSERT INTO delivery_representatives (user_id, name, email, is_active, created_at, updated_at) '
            . 'VALUES (?, ?, ?, 1, ?, ?)'
        );
        $statement->execute([$userId, "Rep {$tag}", "rep-{$tag}@example.test", $now, $now]);
        $representativeId = (int) $pdo->lastInsertId();

        $statement = $pdo->prepare(
            'INSERT INTO shipment_assignments '
            . '(shipment_id, delivery_representative_id, status, assigned_at, created_at, updated_at) '
            . 'VALUES (?, ?, ?, ?, ?, ?)'
        );
        $statement->execute([$shipmentId, $representativeId, 'active', $now, $now, $now]);
        $assignmentId = (int) $pdo->lastInsertId();

        return [$userId, $representativeId, $shipmentId, $orderId, $assignmentId];
    }

    private function cleanupCommittedFixture(
        \PDO $pdo,
        ?int $shipmentId,
        ?int $assignmentId,
        ?int $representativeId,
        ?int $orderId,
        ?int $userId
    ): void {
        foreach ([
            'DELETE FROM shipment_delivery_attempts WHERE shipment_id = ' . (int) $shipmentId,
            'DELETE FROM shipment_assignments WHERE shipment_id = ' . (int) $shipmentId,
            'DELETE FROM delivery_representatives WHERE id = ' . (int) $representativeId,
            'DELETE FROM shipments WHERE id = ' . (int) $shipmentId,
            'DELETE FROM orders WHERE id = ' . (int) $orderId,
            'DELETE FROM users WHERE id = ' . (int) $userId,
        ] as $sql) {
            $pdo->exec($sql);
        }
    }

    private function createIndependentConnection(): \PDO
    {
        $config = config('database.connections.mysql');

        return new \PDO(
            sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                $config['host'],
                $config['port'],
                $config['database']
            ),
            $config['username'],
            $config['password'],
            [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
    }
}
