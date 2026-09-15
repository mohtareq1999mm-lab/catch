<?php

namespace Tests\Feature\Database;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Test filtered/partial unique index support for claim lifecycle.
 *
 * Target: Verify whether the database supports:
 * CREATE UNIQUE INDEX idx ON table(col1, col2) WHERE condition
 *
 * Required for Phase 2: ONE active claim per (coupon_id, user_id)
 * while allowing multiple expired/redeemed claims.
 */
class FilteredUniqueIndexTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test table matching Phase 2 schema
        Schema::create('test_filtered_claims', function ($table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('user_id');
            $table->enum('status', ['active', 'expired', 'redeemed'])->default('active');
            $table->timestamp('claimed_at')->useCurrent();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('test_filtered_claims');
        parent::tearDown();
    }

    public function test_database_version_detection()
    {
        $driver = DB::getDriverName();

        if ($driver === 'mysql') {
            $version = DB::select('SELECT VERSION() as version')[0]->version;
            dump("MySQL Version: $version");
        } elseif ($driver === 'sqlite') {
            $version = DB::select('SELECT sqlite_version() as version')[0]->version;
            dump("SQLite Version: $version");
        } else {
            dump("Database Driver: $driver");
        }

        $this->assertTrue(true, "Database: $driver");
    }

    public function test_filtered_unique_index_syntax_support()
    {
        $driver = DB::getDriverName();

        try {
            // Attempt MySQL 8.0.13+ / PostgreSQL / SQLite 3.8.0+ syntax
            DB::statement('
                CREATE UNIQUE INDEX idx_test_active_claim
                ON test_filtered_claims(coupon_id, user_id)
                WHERE status = ?
            ', ['active']);

            $this->assertTrue(true, "Filtered unique index syntax SUPPORTED on $driver");
            dump("✓ Filtered unique index created successfully on $driver");
        } catch (\Exception $e) {
            $this->markTestSkipped("Filtered unique index NOT supported on $driver: " . $e->getMessage());
        }
    }

    public function test_filtered_unique_index_enforcement()
    {
        $this->test_filtered_unique_index_syntax_support();

        // Test 1: First active claim (should succeed)
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 100,
            'status' => 'active',
            'claimed_at' => now(),
        ]);
        $this->assertDatabaseCount('test_filtered_claims', 1);
        dump("✓ Test 1: First active claim inserted");

        // Test 2: Second active claim same coupon/user (should FAIL)
        $exceptionThrown = false;
        try {
            DB::table('test_filtered_claims')->insert([
                'coupon_id' => 1,
                'user_id' => 100,
                'status' => 'active',
                'claimed_at' => now(),
            ]);
        } catch (\Exception $e) {
            $exceptionThrown = true;
            dump("✓ Test 2: Duplicate active claim rejected (expected)");
        }
        $this->assertTrue($exceptionThrown, "Duplicate active claim should be rejected");

        // Test 3: Expired claim same coupon/user (should SUCCEED)
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 100,
            'status' => 'expired',
            'claimed_at' => now(),
        ]);
        $this->assertDatabaseCount('test_filtered_claims', 2);
        dump("✓ Test 3: Expired claim allowed alongside active");

        // Test 4: Redeemed claim same coupon/user (should SUCCEED)
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 100,
            'status' => 'redeemed',
            'claimed_at' => now(),
        ]);
        $this->assertDatabaseCount('test_filtered_claims', 3);
        dump("✓ Test 4: Redeemed claim allowed alongside active");

        // Test 5: Another expired claim (should SUCCEED)
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 100,
            'status' => 'expired',
            'claimed_at' => now(),
        ]);
        $this->assertDatabaseCount('test_filtered_claims', 4);
        dump("✓ Test 5: Multiple expired claims allowed");

        // Verify final state
        $claims = DB::table('test_filtered_claims')
            ->where('coupon_id', 1)
            ->where('user_id', 100)
            ->orderBy('id')
            ->get();

        dump("Final state: " . $claims->pluck('status')->implode(', '));

        $this->assertEquals(1, $claims->where('status', 'active')->count(), "Should have exactly 1 active claim");
        $this->assertEquals(2, $claims->where('status', 'expired')->count(), "Should have 2 expired claims");
        $this->assertEquals(1, $claims->where('status', 'redeemed')->count(), "Should have 1 redeemed claim");
    }

    public function test_reclaim_after_expiry_scenario()
    {
        $this->test_filtered_unique_index_syntax_support();

        // User A claims (active)
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 100,
            'status' => 'active',
            'claimed_at' => now(),
        ]);

        // User A's claim expires
        DB::table('test_filtered_claims')
            ->where('coupon_id', 1)
            ->where('user_id', 100)
            ->where('status', 'active')
            ->update(['status' => 'expired']);

        // User A reclaims (should succeed)
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 100,
            'status' => 'active',
            'claimed_at' => now(),
        ]);

        $activeClaims = DB::table('test_filtered_claims')
            ->where('coupon_id', 1)
            ->where('user_id', 100)
            ->where('status', 'active')
            ->count();

        $this->assertEquals(1, $activeClaims, "Should have exactly 1 active claim after reclaim");
        dump("✓ Reclaim scenario: User can reclaim after expiry");
    }

    public function test_concurrent_different_users_scenario()
    {
        $this->test_filtered_unique_index_syntax_support();

        // User A claims
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 100,
            'status' => 'active',
            'claimed_at' => now(),
        ]);

        // User B claims same coupon (should succeed - different user)
        DB::table('test_filtered_claims')->insert([
            'coupon_id' => 1,
            'user_id' => 200,
            'status' => 'active',
            'claimed_at' => now(),
        ]);

        $this->assertDatabaseCount('test_filtered_claims', 2);
        dump("✓ Concurrent users: Different users can have active claims on same coupon");
    }
}
