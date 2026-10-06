<?php

namespace Tests\Feature\Wms;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * P9-9: API + RBAC completion audit as executable proof.
 *
 * Every WMS route (all Api\Admin\Wms controllers + the order
 * cancel/shipment surface) must carry authentication, a permission gate,
 * and the admin throttle. Permission-less callers get 403 on every route
 * (the gate fires before any lookup or validation); unauthenticated
 * callers get 401. Seeded operational permissions are audited for binding.
 * Sequential only (shared MySQL).
 */
class WmsRbacCompletionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Mirror the production seeder surface (P9-7a): bound perms plus
        // the reserved-by-design unbound set asserted below.
        foreach ([
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
            'view-fulfillment', 'manage-fulfillment', 'picking-execute',
            'packing-execute', 'packing.complete', 'fulfillment.create',
            'fulfillment.cancel', 'picking.claim', 'picking.complete',
            'batch.manage', 'batch.operate', 'order.cancel-during-fulfillment',
            'view-shipment', 'view-shipments', 'create-shipment', 'update-shipment',
            'inventory-adjust',
        ] as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'api']);
        }
    }

    /**
     * @return array<int, array{method: string, uri: string, name: string}>
     */
    private function wmsRoutes(): array
    {
        $routes = [];
        foreach (Route::getRoutes() as $route) {
            $action = $route->getAction();
            $controller = $action['controller'] ?? '';
            $name = $route->getName() ?? '';
            $isWms = str_contains($controller, 'Api\\Admin\\Wms\\')
                || $name === 'api.admin.orders.cancel'
                || str_starts_with($name, 'api.admin.orders.shipment.');
            if (!$isWms) {
                continue;
            }
            $method = array_intersect($route->methods(), ['GET', 'POST', 'PUT', 'PATCH', 'DELETE']);
            $method = reset($method) ?: 'GET';
            $uri = '/' . ltrim($route->uri(), '/');
            $uri = str_replace(['{id}', '{orderId}', '{order}'], '1', $uri);
            // Required query filters for list endpoints.
            if (str_contains($uri, 'admin/packages') && !str_contains($uri, '?')) {
                $uri .= str_ends_with($uri, '/1') ? '' : '?fulfillment_id=1';
            }
            if (str_contains($uri, 'admin/shipments') && !str_contains($uri, '?') && !str_ends_with($uri, '/1')) {
                $uri .= '?fulfillment_id=1';
            }
            $routes[] = ['method' => $method, 'uri' => $uri, 'name' => $name];
        }

        return $routes;
    }

    public function test_wms_surface_inventory_is_complete(): void
    {
        $routes = $this->wmsRoutes();

        // P9-2 (14) + P9-3/P9-4 fulfillments (7) + fulfillment-items (1) +
        // picking-tasks (8) + batches (10) + P9-6 packing (15) + P9-7 (1) +
        // P9-8 shipments (6) + pre-existing order shipment (2) = 64.
        $this->assertCount(64, $routes);

        $names = array_column($routes, 'name');
        foreach ([
            'api.admin.batches.refresh-progress',
            'api.admin.packing-tasks.pack', 'api.admin.packing-tasks.verify',
            'api.admin.packages.void', 'api.admin.orders.cancel',
            'api.admin.fulfillments.shipments.store',
            'api.admin.shipments.dispatch', 'api.admin.shipments.deliver',
            'api.admin.shipments.cancel',
        ] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_every_wms_route_requires_authentication(): void
    {
        // Throttle is proven present by middleware audit below; bypass it
        // here so the 64-request sweep measures auth, not rate limits.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        foreach ($this->wmsRoutes() as $route) {
            $response = $this->call($route['method'], $route['uri'], [], [], [], [], '{}');
            $this->assertSame(
                401, $response->status(),
                "Expected 401 for unauthenticated {$route['method']} {$route['uri']} ({$route['name']})"
            );
        }
    }

    public function test_every_wms_route_requires_permission(): void
    {
        // Same throttle note as above: this sweep measures the permission
        // gate, not rate limits.
        $this->withoutMiddleware(\Illuminate\Routing\Middleware\ThrottleRequests::class);

        $user = User::factory()->create(['type' => 'staff']);

        foreach ($this->wmsRoutes() as $route) {
            $response = $this->actingAs($user, 'sanctum')
                ->call($route['method'], $route['uri'], [], [], [], [], '{}');
            $this->assertSame(
                403, $response->status(),
                "Expected 403 for permission-less {$route['method']} {$route['uri']} ({$route['name']})"
            );
        }
    }

    public function test_every_wms_route_has_throttle_and_single_permission_gate(): void
    {
        foreach (Route::getRoutes() as $route) {
            $controller = $route->getAction()['controller'] ?? '';
            if (!str_contains($controller, 'Api\\Admin\\Wms\\')) {
                continue;
            }
            $middleware = $route->gatherMiddleware();
            $flat = implode('|', array_map(fn ($m) => is_string($m) ? $m : '', $middleware));

            $this->assertStringContainsString(
                'throttle:admin', $flat, "Missing throttle on {$route->getName()}"
            );
            $this->assertStringContainsString(
                'auth:sanctum', $flat, "Missing auth on {$route->getName()}"
            );
            $this->assertMatchesRegularExpression(
                '/permission:/', $flat, "Missing permission gate on {$route->getName()}"
            );
        }
    }

    public function test_seeded_operational_permissions_binding_audit(): void
    {
        $gated = [];
        foreach (Route::getRoutes() as $route) {
            $flat = implode('|', array_map(
                fn ($m) => is_string($m) ? $m : '',
                $route->gatherMiddleware()
            ));
            if (preg_match_all('/permission:([a-z0-9_|.\-]+)/i', $flat, $m)) {
                foreach (explode('|', $m[1][0]) as $perm) {
                    $gated[trim($perm)] = true;
                }
            }
        }

        // Every permission introduced for P9-5..P9-8 must gate ≥1 route.
        foreach ([
            'packing-execute', 'packing.complete',
            'order.cancel-during-fulfillment',
            'view-shipment', 'create-shipment', 'update-shipment',
            'batch.manage', 'batch.operate', 'picking-execute',
            'view-fulfillment', 'manage-fulfillment',
            'fulfillment.create', 'fulfillment.cancel',
            'view-warehouse', 'manage-warehouse', 'view-location', 'manage-location',
        ] as $bound) {
            $this->assertArrayHasKey($bound, $gated, "Seeded permission {$bound} gates no route");
        }

        // Documented unbound by design: picking.claim / picking.complete are
        // reserved granular claims for a future tightening of the P9-4
        // picking surface (closed phases stay frozen); inventory-adjust has
        // no HTTP surface by design (inventory keeps zero HTTP writers).
        foreach (['picking.claim', 'picking.complete', 'inventory-adjust'] as $reserved) {
            $this->assertArrayNotHasKey($reserved, $gated, "{$reserved} unexpectedly bound");
            $this->assertNotNull(
                Permission::where('name', $reserved)->where('guard_name', 'api')->first(),
                "Reserved permission {$reserved} must still be seeded"
            );
        }
    }
}
