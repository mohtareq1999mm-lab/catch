<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as PermissionModel;
use Spatie\Permission\PermissionRegistrar;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class ShippingProviderStatusTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $admin;

    private User $unprivileged;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        // Worktrees have no .env; encryption is needed only for the HTTP
        // session helpers, not for the code under test.
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
        $this->createAllTestTables();

        config(['shipping.providers' => [
            'carrier' => [
                'adapter' => 'Tests\\Support\\Shipping\\ConfiguredCarrierGateway',
                'mode' => 'sandbox',
                'account_code' => 'EG-ACCOUNT',
                'channels' => ['web'],
                'geographies' => ['EG'],
                'polling' => [
                    'supported' => false,
                    'cursor_type' => null,
                    'pagination' => null,
                    'overlap_window_seconds' => 0,
                ],
                'modes' => [
                    'sandbox' => [
                        'enabled' => true,
                        'base_url' => 'https://sandbox.example.test',
                        'credentials' => ['api_key' => 'api-key-secret', 'api_secret' => 'api-secret-secret'],
                        'queues' => ['dispatch' => 'shipping.dispatch-private'],
                        'webhook' => ['secret' => 'hook-secret', 'version' => 'v1'],
                        'capability_verification' => [
                            'quote' => ['verified_at' => '2026-11-01T00:00:00Z', 'evidence' => 'private-evidence-id'],
                        ],
                        'status_map' => ['version' => 3, 'statuses' => []],
                    ],
                    'production' => [
                        'enabled' => false,
                        'base_url' => 'https://api.example.test',
                        'credentials' => ['api_key' => null],
                        'capability_verification' => [],
                    ],
                ],
            ],
        ]]);

        $this->admin = User::firstOrCreate(
            ['email' => 'shipping-admin@example.com'],
            [
                'name' => 'Shipping Admin',
                'password' => bcrypt('password'),
                'type' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );
        $this->unprivileged = User::firstOrCreate(
            ['email' => 'shipping-readonly@example.com'],
            [
                'name' => 'Readonly User',
                'password' => bcrypt('password'),
                'type' => 'admin',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        $permission = PermissionModel::firstOrCreate([
            'name' => Permission::SHIPPING_PROVIDER_STATUS_VIEW,
            'guard_name' => 'api',
        ]);
        $this->admin->syncPermissions([$permission]);
        $this->unprivileged->syncPermissions([]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_index_returns_only_mode_enablement_and_verified_capabilities(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/shipping/providers');

        $response->assertOk();
        $row = $response->json('data.0');
        $this->assertSame(['provider', 'mode', 'enabled', 'verified_capabilities', 'mapping_version_present'], array_keys($row));
        $this->assertTrue($row['mapping_version_present']);
        $this->assertSame('carrier', $row['provider']);
        $this->assertSame('sandbox', $row['mode']);
        $this->assertTrue($row['enabled']);
        $this->assertSame(['quote'], $row['verified_capabilities']);
        $this->assertStatusResponseRedacted($response->json());
    }

    public function test_show_returns_one_redacted_provider(): void
    {
        Sanctum::actingAs($this->admin);

        $response = $this->getJson('/api/v1/admin/shipping/providers/carrier');

        $response->assertOk();
        $this->assertSame('carrier', $response->json('data.provider'));
        $this->assertSame('sandbox', $response->json('data.mode'));
        $this->assertSame(['quote'], $response->json('data.verified_capabilities'));
        $this->assertStatusResponseRedacted($response->json());
    }

    public function test_unknown_provider_returns_404(): void
    {
        Sanctum::actingAs($this->admin);

        $this->getJson('/api/v1/admin/shipping/providers/ghost')->assertNotFound();
    }

    public function test_permission_denial_is_403_for_list_and_detail(): void
    {
        Sanctum::actingAs($this->unprivileged);

        $this->getJson('/api/v1/admin/shipping/providers')->assertForbidden();
        $this->getJson('/api/v1/admin/shipping/providers/carrier')->assertForbidden();
    }

    public function test_provider_status_routes_are_read_only(): void
    {
        $methods = [];
        foreach (Route::getRoutes() as $route) {
            if (str_contains($route->uri(), 'admin/shipping/providers')) {
                $methods = array_merge($methods, $route->methods());
            }
        }

        $this->assertNotEmpty($methods);
        $this->assertSame([], array_values(array_diff($methods, ['GET', 'HEAD'])));
    }

    /** @param array<string,mixed> $payload */
    private function assertStatusResponseRedacted(array $payload): void
    {
        $body = json_encode($payload);
        $this->assertIsString($body);

        foreach ([
            'api-key-secret',
            'api-secret-secret',
            'hook-secret',
            'private-evidence-id',
            'EG-ACCOUNT',
            'sandbox.example.test',
            'shipping.dispatch-private',
        ] as $secret) {
            $this->assertStringNotContainsString($secret, $body);
        }
    }
}
