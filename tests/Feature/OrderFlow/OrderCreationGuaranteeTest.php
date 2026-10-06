<?php

declare(strict_types=1);

namespace Tests\Feature\OrderFlow;

use App\Models\OrderFlow\OrderFlow;
use App\Models\OrderFlow\OrderStatus;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * P2-2 (D6/C6) + P2-3: the database itself guarantees "every order has
 * one flow" — together with the model-level creation backstop no creation
 * path (service, Marvel repository, seeder, fixture, future code) can
 * manufacture a flow-less row.
 *
 * - Order::creating assigns the resolved flow + a stage mirror consistent
 *   with the creating status (never overwrites an explicit choice).
 * - Requiring the migration backfills legacy NULLs (active local flow +
 *   stage mirror) and enforces NOT NULL on both columns.
 * - Fail-closed paths (no active flow, unmapped status) abort with an
 *   error and write nothing — never guessed.
 */
class OrderCreationGuaranteeTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        $this->customer = User::create([
            'name' => 'Creation Guarantee Customer',
            'email' => 'creation-guarantee-' . Str::random(6) . '@example.com',
            'password' => bcrypt('password'),
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function requireFlowColumns(): void
    {
        if (!Schema::hasColumn('orders', 'flow_id')) {
            $this->markTestSkipped('Flow columns not migrated (pre-flow migration window).');
        }
    }

    private function localFlowId(): int
    {
        return (int) OrderFlow::query()->where('shipping_type', 'local')->where('is_active', true)->value('id');
    }

    private function statusId(string $code): int
    {
        return (int) OrderStatus::query()->where('code', $code)->value('id');
    }

    private function migration(): object
    {
        /** @var object $migration */
        $migration = require database_path('migrations/2026_10_04_000001_backfill_order_flow_guarantee.php');

        return $migration;
    }

    /** @test */
    public function bare_create_receives_local_flow_and_pending_mirror(): void
    {
        $this->requireFlowColumns();

        $order = Order::create(['user_id' => $this->customer->id]);

        $fresh = $order->fresh();
        $this->assertSame('local', $fresh->shipping_type);
        $this->assertSame($this->localFlowId(), (int) $fresh->flow_id);
        $this->assertSame('pending', $fresh->status);
        $this->assertSame($this->statusId('pending'), (int) $fresh->current_status_id);
    }

    /** @test */
    public function hook_mirrors_explicit_status_without_changing_it(): void
    {
        $this->requireFlowColumns();

        $order = Order::create(['user_id' => $this->customer->id, 'status' => 'completed']);

        $fresh = $order->fresh();
        $this->assertSame('completed', $fresh->status);
        $this->assertSame($this->statusId('completed'), (int) $fresh->current_status_id);
        $this->assertSame($this->localFlowId(), (int) $fresh->flow_id);
    }

    /** @test */
    public function hook_leaves_explicit_flow_assignment_untouched(): void
    {
        $this->requireFlowColumns();

        $internationalFlowId = (int) OrderFlow::query()
            ->where('shipping_type', 'international')
            ->where('is_active', true)
            ->value('id');
        $this->assertNotSame(0, $internationalFlowId, 'International flow must be seeded.');

        $flowService = app(\App\Services\OrderFlow\OrderFlowService::class);
        $order = Order::create(['user_id' => $this->customer->id, 'status' => 'pending']);
        $flowService->assignFlowToOrder($order->refresh(), 'international');

        $fresh = $order->fresh();
        $this->assertSame($internationalFlowId, (int) $fresh->flow_id);
        $this->assertSame('international', $fresh->shipping_type);
    }

    /** @test */
    public function hook_rejects_creation_without_active_local_flow(): void
    {
        $this->requireFlowColumns();

        OrderFlow::query()->where('shipping_type', 'local')->update(['is_active' => false]);

        try {
            Order::create(['user_id' => $this->customer->id]);
            $this->fail('Creation must abort when no active local flow exists.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('shipping', strtolower($e->getMessage()));
        }

        $this->assertSame(0, Order::query()->where('user_id', $this->customer->id)->count());

        OrderFlow::query()->where('shipping_type', 'local')->update(['is_active' => true]);
    }

    /** @test */
    public function migration_backfills_legacy_null_rows_with_mirror(): void
    {
        $this->requireFlowColumns();

        // The new constraint already landed on this database, so temporarily
        // replicate down() (nullable columns) to reconstruct the legacy
        // pre-upgrade picture, then let up() do the real upgrade work.
        $this->migration()->down();

        $order = Order::create(['user_id' => $this->customer->id, 'status' => 'processing']);

        // Simulate a legacy flow-less row (hook auto-assignment bypassed by
        // direct update now that the columns are nullable again).
        DB::table('orders')->where('id', $order->id)->update(['flow_id' => null, 'current_status_id' => null]);
        $this->assertSame(1, (int) DB::table('orders')->where('id', $order->id)->whereNull('flow_id')->count());

        $this->migration()->up();

        $row = DB::table('orders')->where('id', $order->id)->first();
        $this->assertSame($this->localFlowId(), (int) $row->flow_id);
        $this->assertSame('processing', $row->status);
        $this->assertSame($this->statusId('processing'), (int) $row->current_status_id);
        $this->assertSame(0, (int) DB::table('orders')->whereNull('flow_id')->count());
        $this->assertSame(0, (int) DB::table('orders')->whereNull('current_status_id')->count());

        // The upgrade re-landed the NOT NULL guarantee.
        $this->assertSame('NO', $this->columnNullability('flow_id'));
        $this->assertSame('NO', $this->columnNullability('current_status_id'));

        // DDL issues implicit commits, so tearDown's rollback cannot clean
        // up behind these two migration tests — remove the fixtures by hand.
        $this->cleanupFixtures();
    }

    /** @test */
    public function migration_fails_closed_on_unmapped_status(): void
    {
        $this->requireFlowColumns();

        $this->migration()->down();

        $order = Order::create(['user_id' => $this->customer->id, 'status' => 'pending']);

        // Simulate a legacy flow-less row whose status has no catalog row.
        DB::table('orders')->where('id', $order->id)->update(['flow_id' => null, 'current_status_id' => null]);

        // Retire the pending catalog row for the duration of this test.
        // DDL punctures the DatabaseTransactions wrapper (implicit commits),
        // so the row is captured and restored BY HAND below — tearDown's
        // rollback cannot be relied on here.
        $pending = (array) DB::table('order_statuses')->where('code', 'pending')->first();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        DB::table('order_statuses')->where('code', 'pending')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');

        try {
            $this->migration()->up();
            $this->fail('Backfill must abort on unmapped statuses, never guess.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('unmapped', strtolower($e->getMessage()));
        }

        // Nothing was written AND no partial enforcement happened: the
        // legacy row is still flow-less and the columns stayed nullable.
        $row = DB::table('orders')->where('id', $order->id)->first();
        $this->assertNull($row->flow_id);
        $this->assertNull($row->current_status_id);
        $this->assertSame('YES', $this->columnNullability('flow_id'));
        $this->assertSame('YES', $this->columnNullability('current_status_id'));

        // Restore the catalog row with its ORIGINAL id: the flow-stage
        // links were never touched, so the fixture is whole again.
        DB::table('order_statuses')->insert($pending);

        // DDL issues implicit commits — clean up by hand (see above).
        $this->cleanupFixtures();
    }

    private function columnNullability(string $column): string
    {
        $row = DB::selectOne(
            'SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [DB::getDatabaseName(), 'orders', $column]
        );

        return (string) $row->IS_NULLABLE;
    }

    /**
     * MySQL DDL issues implicit commits, which punctures the surrounding
     * DatabaseTransactions wrapper — the two migration tests remove their
     * own fixtures explicitly so the scratch database stays clean.
     */
    private function cleanupFixtures(): void
    {
        DB::table('orders')->where('user_id', $this->customer->id)->delete();
        DB::table('users')->where('id', $this->customer->id)->delete();
    }

    /** @test */
    public function database_rejects_raw_rows_without_flow_columns(): void
    {
        $this->requireFlowColumns();

        // A preceding DDL test may have left the columns nullable (its abort
        // path asserts exactly that) — re-land the guarantee first, which
        // also proves up() is idempotent on a compliant database.
        $this->migration()->up();

        try {
            DB::table('orders')->insert([
                'user_id' => $this->customer->id,
                'status' => 'pending',
                'shipping_type' => 'local',
            ]);
            $this->fail('The database must reject rows without flow columns (NOT NULL).');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('flow_id', strtolower($e->getMessage()));
        }

        try {
            DB::table('orders')->insert([
                'user_id' => $this->customer->id,
                'status' => 'pending',
                'shipping_type' => 'local',
                'flow_id' => $this->localFlowId(),
            ]);
            $this->fail('The database must reject rows without current_status_id (NOT NULL).');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('current_status_id', strtolower($e->getMessage()));
        }
    }
}
