<?php

namespace Tests\Feature\Coupon;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Marvel\Database\Models\Coupon;
use Marvel\Database\Models\CouponTargeting;
use Tests\TestCase;

/**
 * Multi-Connection FOR UPDATE Blocking Test
 *
 * Tests that FOR UPDATE on coupon_targetings actually blocks concurrent access.
 * Requires MySQL/TiDB (not SQLite).
 */
class MultiConnectionLockTest extends TestCase
{
    use RefreshDatabase;

    private function createCoupon(array $overrides = []): Coupon
    {
        $code = 'TEST-' . Str::random(8);
        return Coupon::create(array_merge([
            'code' => $code,
            'slug' => Str::slug($code),
            'name' => 'Test Coupon',
            'discount_type' => 'percentage',
            'discount' => 10,
            'start_date' => now()->subDay(),
            'end_date' => now()->addMonth(),
            'status' => true,
        ], $overrides));
    }

    protected function setUp(): void
    {
        parent::setUp();

        if (DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Multi-connection test requires MySQL/TiDB');
        }
    }

    /**
     * Test that Connection B blocks when Connection A holds FOR UPDATE lock
     *
     * @group concurrency
     * @group slow
     */
    public function test_for_update_blocks_second_connection()
    {
        // Setup: Create coupon and targeting
        $coupon = $this->createCoupon();
        $targeting = CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment',
            'require_claim' => true,
            'max_claims' => 5,
            'rule_tree' => null,
        ]);

        // Create two independent PDO connections
        $connectionA = $this->createIndependentConnection();
        $connectionB = $this->createIndependentConnection();

        try {
            // Connection A: Begin transaction and acquire FOR UPDATE lock
            $connectionA->beginTransaction();

            $stmtA = $connectionA->prepare(
                "SELECT * FROM coupon_targetings WHERE coupon_id = ? FOR UPDATE"
            );
            $stmtA->execute([$coupon->id]);
            $resultA = $stmtA->fetch(\PDO::FETCH_ASSOC);

            $this->assertNotNull($resultA, 'Connection A should acquire lock');

            // Connection B: Try to acquire same lock with timeout
            $connectionB->setAttribute(\PDO::ATTR_TIMEOUT, 2);
            $connectionB->beginTransaction();

            $startTime = microtime(true);
            $blocked = false;

            try {
                // Set lock wait timeout to 2 seconds
                $connectionB->exec("SET SESSION innodb_lock_wait_timeout = 2");

                $stmtB = $connectionB->prepare(
                    "SELECT * FROM coupon_targetings WHERE coupon_id = ? FOR UPDATE"
                );
                $stmtB->execute([$coupon->id]);

                $duration = microtime(true) - $startTime;

                // If we got here before A released, test failed
                $this->fail(
                    "Connection B should have blocked but acquired lock in {$duration}s"
                );
            } catch (\PDOException $e) {
                $duration = microtime(true) - $startTime;

                // Check if it was a lock wait timeout (expected)
                if (str_contains($e->getMessage(), 'Lock wait timeout') ||
                    str_contains($e->getMessage(), '1205')) {
                    $blocked = true;

                    // Should have waited approximately 2 seconds
                    $this->assertGreaterThanOrEqual(
                        1.5,
                        $duration,
                        "Connection B should have blocked for ~2 seconds, blocked for {$duration}s"
                    );

                    $this->assertLessThanOrEqual(
                        3.0,
                        $duration,
                        "Lock wait should timeout around 2 seconds, got {$duration}s"
                    );
                } else {
                    throw $e; // Unexpected error
                }
            }

            $this->assertTrue($blocked, 'Connection B should have been blocked by Connection A lock');

            // Connection A: Release lock
            $connectionA->commit();

            // Now Connection B should be able to acquire
            $connectionB->rollBack(); // Clean up B's failed transaction
            $connectionB->beginTransaction();

            $stmtB2 = $connectionB->prepare(
                "SELECT * FROM coupon_targetings WHERE coupon_id = ? FOR UPDATE"
            );
            $stmtB2->execute([$coupon->id]);
            $resultB = $stmtB2->fetch(\PDO::FETCH_ASSOC);

            $this->assertNotNull($resultB, 'Connection B should acquire lock after A releases');

            $connectionB->commit();

        } finally {
            // Cleanup: Close connections
            if (isset($connectionA) && $connectionA->inTransaction()) {
                $connectionA->rollBack();
            }
            if (isset($connectionB) && $connectionB->inTransaction()) {
                $connectionB->rollBack();
            }
        }
    }

    /**
     * Test that ROLLBACK releases FOR UPDATE lock
     *
     * @group concurrency
     */
    public function test_rollback_releases_for_update_lock()
    {
        $coupon = $this->createCoupon();
        $targeting = CouponTargeting::create([
            'coupon_id' => $coupon->id,
            'mode' => 'assignment',
            'require_claim' => true,
            'max_claims' => 5,
            'rule_tree' => null,
        ]);

        $connectionA = $this->createIndependentConnection();
        $connectionB = $this->createIndependentConnection();

        try {
            // Connection A: Acquire lock
            $connectionA->beginTransaction();
            $stmtA = $connectionA->prepare(
                "SELECT * FROM coupon_targetings WHERE coupon_id = ? FOR UPDATE"
            );
            $stmtA->execute([$coupon->id]);
            $stmtA->fetch();

            // Connection A: Rollback (release lock)
            $connectionA->rollBack();

            // Connection B: Should immediately acquire (no timeout needed)
            $connectionB->beginTransaction();

            $startTime = microtime(true);
            $stmtB = $connectionB->prepare(
                "SELECT * FROM coupon_targetings WHERE coupon_id = ? FOR UPDATE"
            );
            $stmtB->execute([$coupon->id]);
            $resultB = $stmtB->fetch(\PDO::FETCH_ASSOC);
            $duration = microtime(true) - $startTime;

            $this->assertNotNull($resultB, 'Connection B should acquire lock after A rolls back');

            // Should be very fast (< 0.5s) since lock was released
            $this->assertLessThan(
                0.5,
                $duration,
                "Lock should be immediately available after rollback, took {$duration}s"
            );

            $connectionB->commit();

        } finally {
            if (isset($connectionA) && $connectionA->inTransaction()) {
                $connectionA->rollBack();
            }
            if (isset($connectionB) && $connectionB->inTransaction()) {
                $connectionB->rollBack();
            }
        }
    }

    /**
     * Create an independent PDO connection
     * Does NOT use Laravel's connection pool
     */
    private function createIndependentConnection(): \PDO
    {
        $config = config('database.connections.mysql');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $config['host'],
            $config['port'],
            $config['database']
        );

        $options = [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // Apply DB_INIT_COMMAND if configured (TiDB pessimistic mode)
        if ($initCommand = env('DB_INIT_COMMAND')) {
            $options[\PDO::MYSQL_ATTR_INIT_COMMAND] = $initCommand;
        }

        return new \PDO(
            $dsn,
            $config['username'],
            $config['password'],
            $options
        );
    }
}
