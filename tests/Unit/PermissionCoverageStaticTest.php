<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Permission coverage (static source audit).
 *
 * Policy: every permission name referenced by route-level `permission:`
 * middleware in the Marvel admin route file MUST exist in the
 * PermissionSeeder creation set — otherwise the gate 403s for every
 * caller, including super_admin (root cause of admin@demo.com
 * "User does not have the required permissions" on bulk product delete:
 * `delete-all-products` was route-referenced but never seeded).
 *
 * Precision: DELETE /api/v1/products/all (destroyAll, single perm) 403d for
 * every admin; POST products/bulk-delete (delete-all-products|delete-product)
 * stayed passable via delete-product.
 *
 * Dotted names (payments.*, change-order-status.*) resolve via their own
 * seeders and are asserted present individually below.
 */
class PermissionCoverageStaticTest extends TestCase
{
    private function routesSource(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../packages/marvel/src/Rest/Routes.php');
    }

    private function seederSource(): string
    {
        return (string) file_get_contents(__DIR__ . '/../../database/seeders/PermissionSeeder.php');
    }

    /** @return string[] */
    private function routeReferencedPermissions(): array
    {
        $routes = $this->routesSource();
        preg_match_all("/permission:([A-Za-z0-9_.\\-|]+)/", $routes, $m);
        $names = [];
        foreach ($m[1] as $group) {
            foreach (explode('|', $group) as $name) {
                $name = trim($name);
                if ($name !== '') {
                    $names[$name] = true;
                }
            }
        }
        // Enum-concatenated gates: permission:' . Permission::X
        preg_match_all('/Permission::([A-Z0-9_]+)/', $routes, $em);
        $enumMap = $this->permissionEnumMap();
        foreach (array_unique($em[1]) as $const) {
            $this->assertArrayHasKey($const, $enumMap, "Permission::{$const} referenced in routes but missing from Marvel\\Enums\\Permission");
            $names[$enumMap[$const]] = true;
        }
        return array_keys($names);
    }

    /** @return array<string,string> const => permission name */
    private function permissionEnumMap(): array
    {
        $src = (string) file_get_contents(__DIR__ . '/../../packages/marvel/src/Enums/Permission.php');
        preg_match_all("/public const ([A-Z0-9_]+)\\s*=\\s*'([^']+)'/", $src, $m, PREG_SET_ORDER);
        $map = [];
        foreach ($m as $row) {
            $map[$row[1]] = $row[2];
        }
        return $map;
    }

    public function test_every_route_referenced_permission_is_seeded(): void
    {
        $seeder = $this->seederSource();
        $missing = [];
        foreach ($this->routeReferencedPermissions() as $name) {
            // Granular per-status permissions are created dynamically by
            // OrderStatusPermissionSeeder, not the master list.
            if (str_starts_with($name, 'change-order-status.')) {
                continue;
            }
            if (!str_contains($seeder, "'" . $name . "'") && !str_contains($seeder, '"' . $name . '"')) {
                $missing[] = $name;
            }
        }
        $this->assertSame([], $missing, 'Route-referenced permissions missing from PermissionSeeder: ' . implode(', ', $missing));
    }

    public function test_bulk_product_delete_permission_is_seeded(): void
    {
        // Regression: delete-all-products was route-gated but had no seeder
        // row, so bulk delete 403d for every admin.
        $this->assertStringContainsString("'delete-all-products'", $this->seederSource());
    }

    public function test_previously_ungated_admin_endpoints_now_carry_middleware(): void
    {
        $routes = $this->routesSource();
        $this->assertStringContainsString("governorates', [CountryController::class, 'governorates'])->middleware(['auth:sanctum', 'permission:view-country'])", $routes);
        $this->assertStringContainsString("cities', [GovernorateController::class, 'cities'])->middleware(['auth:sanctum', 'permission:view-governorate'])", $routes);
        $this->assertStringContainsString("getFlashSaleInfoByProductID'])->middleware('permission:view-flash-sale|view-products')", $routes);
        $this->assertStringContainsString("'permission:export-analytics'])->group(function () {", $routes);
        $this->assertStringContainsString("validate-config')->middleware('permission:view-coupons|update-coupon|create-coupon')", $routes);
        $this->assertStringContainsString("targeting.upsert')->middleware('permission:update-coupon|create-coupon')", $routes);
        $this->assertStringContainsString("distribute'])->whereNumber('id')->middleware('permission:update-coupon')", $routes);
    }
}
