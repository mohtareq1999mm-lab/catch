<?php

namespace Tests\Unit\Services\Warehouse;

use App\Models\Fulfillment\Warehouse;
use App\Services\Warehouse\WarehouseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 1 — warehouse default/status hardening (§§8-10,31).
 */
class WarehouseDefaultTest extends TestCase
{
    use RefreshDatabase;

    private WarehouseService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(WarehouseService::class);
    }

    public function test_explicit_selection_wins_over_default(): void
    {
        $a = Warehouse::create(['code' => 'A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        $b = Warehouse::create(['code' => 'B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);

        $this->assertEquals($b->id, $this->service->resolveForNewFulfillment($b->id)->id);
        $this->assertEquals($a->id, $this->service->resolveForNewFulfillment(null)->id);
    }

    public function test_inactive_warehouse_rejected_for_new_fulfillment(): void
    {
        $inactive = Warehouse::create(['code' => 'X', 'name' => 'X', 'status' => 'inactive', 'is_default' => false]);

        $this->expectException(\RuntimeException::class);
        $this->service->resolveForNewFulfillment($inactive->id);
    }

    public function test_inactive_default_rejected(): void
    {
        Warehouse::create(['code' => 'D', 'name' => 'D', 'status' => 'inactive', 'is_default' => true]);

        $this->expectException(\RuntimeException::class);
        $this->service->resolveForNewFulfillment(null);
    }

    public function test_set_default_moves_flag_transactionally(): void
    {
        $a = Warehouse::create(['code' => 'A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        $b = Warehouse::create(['code' => 'B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);

        $this->service->setDefault($b->id);

        $this->assertFalse((bool) $a->refresh()->is_default);
        $this->assertTrue((bool) $b->refresh()->is_default);
        $this->assertEquals(1, Warehouse::where('is_default', true)->count());
    }

    public function test_deactivate_default_blocked_without_auto_replacement(): void
    {
        $a = Warehouse::create(['code' => 'A', 'name' => 'A', 'status' => 'active', 'is_default' => true]);
        Warehouse::create(['code' => 'B', 'name' => 'B', 'status' => 'active', 'is_default' => false]);

        try {
            $this->service->deactivate($a->id);
            $this->fail('deactivating default must throw');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('default', strtolower($e->getMessage()));
        }

        // No auto-replacement: B still non-default, A still default+active.
        $this->assertTrue((bool) $a->refresh()->is_default);
        $this->assertEquals('active', $a->refresh()->status);
    }

    public function test_claim_lease_configurable_default(): void
    {
        $this->assertEquals(15, (int) config('fulfillment.claim_lease_minutes', 15));
    }
}
