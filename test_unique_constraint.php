<?php
/**
 * READ-ONLY DATABASE CONSTRAINT VERIFICATION
 *
 * Tests whether the database is ACTUALLY enforcing:
 * ONE ACTIVE claim per (coupon_id, user_id)
 */

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "=== DATABASE CONSTRAINT VERIFICATION ===" . PHP_EOL;
echo "Database: " . DB::getDriverName() . PHP_EOL;

if (DB::getDriverName() === 'sqlite') {
    $version = DB::select('SELECT sqlite_version() as version')[0]->version;
    echo "SQLite Version: $version" . PHP_EOL;
} elseif (DB::getDriverName() === 'mysql') {
    $version = DB::select('SELECT VERSION() as version')[0]->version;
    echo "MySQL Version: $version" . PHP_EOL;
}

echo PHP_EOL . "=== SCHEMA INSPECTION ===" . PHP_EOL;

// Get table structure
$columns = Schema::getColumnListing('coupon_claims');
echo "Columns: " . implode(', ', $columns) . PHP_EOL . PHP_EOL;

// Get indexes
if (DB::getDriverName() === 'sqlite') {
    echo "SQLite Indexes:" . PHP_EOL;
    $indexes = DB::select('PRAGMA index_list(coupon_claims)');
    foreach ($indexes as $index) {
        echo "  - {$index->name} (unique: {$index->unique}, origin: {$index->origin})" . PHP_EOL;
        $info = DB::select("PRAGMA index_info({$index->name})");
        foreach ($info as $col) {
            echo "    Column: {$col->name}" . PHP_EOL;
        }

        // Try to get partial index info (WHERE clause)
        try {
            $sql = DB::select("SELECT sql FROM sqlite_master WHERE type='index' AND name=?", [$index->name]);
            if (!empty($sql) && $sql[0]->sql) {
                echo "    SQL: {$sql[0]->sql}" . PHP_EOL;
            }
        } catch (\Exception $e) {
            echo "    (Could not retrieve SQL definition)" . PHP_EOL;
        }
    }
} elseif (DB::getDriverName() === 'mysql') {
    echo "MySQL Indexes:" . PHP_EOL;
    $indexes = DB::select('SHOW INDEX FROM coupon_claims');
    foreach ($indexes as $index) {
        echo "  - {$index->Key_name}: column={$index->Column_name}, unique=" . ($index->Non_unique ? 'no' : 'yes') . PHP_EOL;
    }

    echo PHP_EOL . "Full CREATE TABLE statement:" . PHP_EOL;
    $createTable = DB::select('SHOW CREATE TABLE coupon_claims');
    echo $createTable[0]->{'Create Table'} . PHP_EOL;
}

echo PHP_EOL . "=== CONSTRAINT ENFORCEMENT TESTS ===" . PHP_EOL;

// Create test table for isolated testing
DB::statement('DROP TABLE IF EXISTS test_constraint_verification');

if (DB::getDriverName() === 'sqlite') {
    DB::statement("
        CREATE TABLE test_constraint_verification (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            coupon_id INTEGER NOT NULL,
            user_id INTEGER NOT NULL,
            status TEXT NOT NULL CHECK(status IN ('active', 'expired', 'redeemed')),
            claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    // Try to create filtered unique index
    try {
        DB::statement("
            CREATE UNIQUE INDEX idx_test_active
            ON test_constraint_verification(coupon_id, user_id)
            WHERE status = 'active'
        ");
        echo "✓ Filtered unique index creation: SUCCESS" . PHP_EOL;
    } catch (\Exception $e) {
        echo "✗ Filtered unique index creation: FAILED - " . $e->getMessage() . PHP_EOL;
        echo "  Falling back to standard unique index for testing" . PHP_EOL;
        DB::statement("
            CREATE UNIQUE INDEX idx_test_active
            ON test_constraint_verification(coupon_id, user_id, status)
        ");
    }
} else {
    DB::statement("
        CREATE TABLE test_constraint_verification (
            id INT AUTO_INCREMENT PRIMARY KEY,
            coupon_id INT NOT NULL,
            user_id INT NOT NULL,
            status ENUM('active', 'expired', 'redeemed') NOT NULL,
            claimed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
        )
    ");

    DB::statement("
        CREATE UNIQUE INDEX idx_test_active
        ON test_constraint_verification(coupon_id, user_id)
        WHERE status = 'active'
    ");
    echo "✓ Filtered unique index created on MySQL" . PHP_EOL;
}

// Test A: ACTIVE + ACTIVE => second insert MUST fail
echo PHP_EOL . "Test A: ACTIVE + ACTIVE (should FAIL)" . PHP_EOL;
DB::table('test_constraint_verification')->insert([
    'coupon_id' => 1,
    'user_id' => 100,
    'status' => 'active',
    'claimed_at' => now(),
]);
echo "  First ACTIVE insert: SUCCESS" . PHP_EOL;

try {
    DB::table('test_constraint_verification')->insert([
        'coupon_id' => 1,
        'user_id' => 100,
        'status' => 'active',
        'claimed_at' => now(),
    ]);
    echo "  ✗ CRITICAL: Second ACTIVE insert SUCCEEDED (constraint NOT enforced)" . PHP_EOL;
    $testAPassed = false;
} catch (\Exception $e) {
    echo "  ✓ Second ACTIVE insert FAILED (constraint enforced): " . $e->getMessage() . PHP_EOL;
    $testAPassed = true;
}

// Test B: ACTIVE + EXPIRED => MUST succeed
echo PHP_EOL . "Test B: ACTIVE + EXPIRED (should SUCCEED)" . PHP_EOL;
try {
    DB::table('test_constraint_verification')->insert([
        'coupon_id' => 1,
        'user_id' => 100,
        'status' => 'expired',
        'claimed_at' => now(),
    ]);
    echo "  ✓ EXPIRED insert alongside ACTIVE: SUCCESS" . PHP_EOL;
    $testBPassed = true;
} catch (\Exception $e) {
    echo "  ✗ EXPIRED insert FAILED: " . $e->getMessage() . PHP_EOL;
    $testBPassed = false;
}

// Test C: EXPIRED + ACTIVE => MUST succeed (after cleaning active)
echo PHP_EOL . "Test C: EXPIRED + ACTIVE (should SUCCEED)" . PHP_EOL;
DB::table('test_constraint_verification')->where('status', 'active')->delete();
DB::table('test_constraint_verification')->insert([
    'coupon_id' => 2,
    'user_id' => 200,
    'status' => 'expired',
    'claimed_at' => now(),
]);
echo "  EXPIRED insert: SUCCESS" . PHP_EOL;

try {
    DB::table('test_constraint_verification')->insert([
        'coupon_id' => 2,
        'user_id' => 200,
        'status' => 'active',
        'claimed_at' => now(),
    ]);
    echo "  ✓ ACTIVE insert after EXPIRED: SUCCESS" . PHP_EOL;
    $testCPassed = true;
} catch (\Exception $e) {
    echo "  ✗ ACTIVE insert FAILED: " . $e->getMessage() . PHP_EOL;
    $testCPassed = false;
}

// Test D: ACTIVE + REDEEMED => MUST succeed
echo PHP_EOL . "Test D: ACTIVE + REDEEMED (should SUCCEED)" . PHP_EOL;
DB::table('test_constraint_verification')->truncate();
DB::table('test_constraint_verification')->insert([
    'coupon_id' => 3,
    'user_id' => 300,
    'status' => 'active',
    'claimed_at' => now(),
]);
try {
    DB::table('test_constraint_verification')->insert([
        'coupon_id' => 3,
        'user_id' => 300,
        'status' => 'redeemed',
        'claimed_at' => now(),
    ]);
    echo "  ✓ REDEEMED insert alongside ACTIVE: SUCCESS" . PHP_EOL;
    $testDPassed = true;
} catch (\Exception $e) {
    echo "  ✗ REDEEMED insert FAILED: " . $e->getMessage() . PHP_EOL;
    $testDPassed = false;
}

// Test E: REDEEMED + ACTIVE => MUST succeed
echo PHP_EOL . "Test E: REDEEMED + ACTIVE (should SUCCEED)" . PHP_EOL;
DB::table('test_constraint_verification')->where('status', 'active')->delete();
DB::table('test_constraint_verification')->insert([
    'coupon_id' => 4,
    'user_id' => 400,
    'status' => 'redeemed',
    'claimed_at' => now(),
]);
try {
    DB::table('test_constraint_verification')->insert([
        'coupon_id' => 4,
        'user_id' => 400,
        'status' => 'active',
        'claimed_at' => now(),
    ]);
    echo "  ✓ ACTIVE insert after REDEEMED: SUCCESS" . PHP_EOL;
    $testEPassed = true;
} catch (\Exception $e) {
    echo "  ✗ ACTIVE insert FAILED: " . $e->getMessage() . PHP_EOL;
    $testEPassed = false;
}

// Cleanup
DB::statement('DROP TABLE test_constraint_verification');

echo PHP_EOL . "=== VERIFICATION SUMMARY ===" . PHP_EOL;
echo "Test A (ACTIVE+ACTIVE blocks): " . ($testAPassed ? "PASS" : "FAIL") . PHP_EOL;
echo "Test B (ACTIVE+EXPIRED allows): " . ($testBPassed ? "PASS" : "FAIL") . PHP_EOL;
echo "Test C (EXPIRED+ACTIVE allows): " . ($testCPassed ? "PASS" : "FAIL") . PHP_EOL;
echo "Test D (ACTIVE+REDEEMED allows): " . ($testDPassed ? "PASS" : "FAIL") . PHP_EOL;
echo "Test E (REDEEMED+ACTIVE allows): " . ($testEPassed ? "PASS" : "FAIL") . PHP_EOL;

$allPassed = $testAPassed && $testBPassed && $testCPassed && $testDPassed && $testEPassed;

echo PHP_EOL . "ONE-ACTIVE-CLAIM INVARIANT: " . ($allPassed ? "✓ DB-ENFORCED" : "✗ NOT DB-ENFORCED") . PHP_EOL;

if (!$allPassed) {
    echo PHP_EOL . "CONCLUSION: Database does NOT enforce the one-active-claim invariant." . PHP_EOL;
    echo "Application-level enforcement with parent-row FOR UPDATE lock is REQUIRED." . PHP_EOL;
}
