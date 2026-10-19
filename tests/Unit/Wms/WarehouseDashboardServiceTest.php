<?php

namespace Tests\Unit\Wms;

use App\Services\Warehouse\WarehouseDashboardService;
use PHPUnit\Framework\TestCase;

class WarehouseDashboardServiceTest extends TestCase
{
    private WarehouseDashboardService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new WarehouseDashboardService();
    }

    public function test_get_permissions_returns_expected_structure_and_categories(): void
    {
        $result = $this->service->getPermissions(null);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('modules', $result);
        $this->assertArrayHasKey('roles', $result);
        $this->assertArrayHasKey('my_wms_permissions', $result);
        $this->assertArrayHasKey('total_wms_permissions', $result);

        $this->assertGreaterThan(20, $result['total_wms_permissions']);

        $moduleKeys = array_column($result['modules'], 'module');
        $this->assertContains('warehouses', $moduleKeys);
        $this->assertContains('locations', $moduleKeys);
        $this->assertContains('fulfillments', $moduleKeys);
        $this->assertContains('picking_and_batches', $moduleKeys);
        $this->assertContains('packing_and_packages', $moduleKeys);
        $this->assertContains('shipments', $moduleKeys);
        $this->assertContains('inventory', $moduleKeys);
        $this->assertContains('workflow', $moduleKeys);
        $this->assertContains('barcode', $moduleKeys);

        // Check roles
        $this->assertArrayHasKey('picker', $result['roles']);
        $this->assertArrayHasKey('packer', $result['roles']);
        $this->assertArrayHasKey('warehouse-supervisor', $result['roles']);
        $this->assertArrayHasKey('warehouse-manager', $result['roles']);

        $this->assertContains('barcode.scan', $result['roles']['picker']['permissions']);
        $this->assertContains('picking-execute', $result['roles']['picker']['permissions']);
        $this->assertContains('packing-execute', $result['roles']['packer']['permissions']);
    }
}
