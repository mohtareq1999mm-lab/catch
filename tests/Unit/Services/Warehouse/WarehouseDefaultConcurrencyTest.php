<?php

namespace Tests\Unit\Services\Warehouse;

use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 2 (P2-4) — default concurrency.
 *
 * HONEST SCOPE: the test harness runs on a single connection inside
 * transactions, so true multi-process races cannot be executed here. These
 * tests prove the SERIALIZED semantics (txn + row lock + partial-unique
 * backstop): sequential competing moves always converge to exactly one
 * active default, and no interleaving can silently duplicate the flag —
 * the DB unique index is the final backstop (verified live in §J).
 */
class WarehouseDefaultConcurrencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequential_competing_moves_converge_to_single_default(): void
    {
        $a = Warehouse::create(['code' => 'A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        $b = Warehouse::create(['code' => 'B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);
        $service = app(WarehouseService::class);

        // Two "competing" admins move the flag back and forth; each move is
        // atomic (txn + lockForUpdate + unique backstop).
        $service->setDefault($b->id);
        $service->setDefault($a->id);
        $service->setDefault($b->id);

        $this->assertEquals(1, Warehouse::where('is_default', true)->count());
        $this->assertTrue($b->refresh()->is_default);
        $this->assertFalse($a->refresh()->is_default);
        $this->assertEquals($b->id, $service->resolveForNewFulfillment(null)->id);
    }

    public function test_deactivate_loser_after_move_keeps_single_default(): void
    {
        $a = Warehouse::create(['code' => 'A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        $b = Warehouse::create(['code' => 'B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);
        $service = app(WarehouseService::class);

        // deactivate-vs-setDefault interleaving: move first, then deactivate
        // the ex-default — legal and converges to one active default.
        $service->setDefault($b->id);
        $service->deactivate($a->id);

        $this->assertEquals(1, Warehouse::where('is_default', true)->count());
        $this->assertEquals('inactive', $a->refresh()->status);
        $this->assertEquals($b->id, $service->resolveForNewFulfillment(null)->id);
    }

    public function test_delete_ex_default_then_promote_keeps_single_default(): void
    {
        $a = Warehouse::create(['code' => 'A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        $b = Warehouse::create(['code' => 'B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);
        $service = app(WarehouseService::class);

        $service->setDefault($b->id);
        $a->delete();
        // Trashed ex-default is invisible to default resolution AND to the
        // partial-unique backstop: exactly one ACTIVE default remains.
        $this->assertEquals(1, Warehouse::where('is_default', true)->count());
        $this->assertEquals($b->id, $service->resolveForNewFulfillment(null)->id);
    }
}
