<?php

declare(strict_types=1);

namespace Tests\Feature\Shipping;

use App\Enums\Shipping\ShippingWebhookIngestOutcome;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission;
use Spatie\Permission\Models\Permission as SpatiePermission;
use Tests\TestCase;

class LaunchApiInventoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        config(['app.key' => 'base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=']);
    }

    private function routeRows(): array
    {
        $rows = [];
        foreach (Route::getRoutes() as $route) {
            $rows[] = [
                'methods' => array_values(array_diff($route->methods(), ['HEAD'])),
                'uri' => $route->uri(),
                'name' => $route->getName(),
                'middleware' => $route->middleware(),
            ];
        }
        return $rows;
    }

    private function routesMatching(string $pattern): array
    {
        return array_values(array_filter($this->routeRows(), fn (array $row): bool => (bool) preg_match($pattern, $row['uri'])));
    }

    public function test_no_shipping_provider_crud_routes_exist(): void
    {
        $rows = $this->routesMatching('#(^|/)api/v1/admin/shipping/providers($|/)#');
        $this->assertNotEmpty($rows);
        foreach ($rows as $row) {
            $this->assertSame(['GET'], $row['methods'], 'Provider surface must be read-only: ' . $row['uri']);
        }
    }

    public function test_no_shipping_pickup_crud_routes_exist(): void
    {
        $this->assertSame([], $this->routesMatching('#shipping.*pickup|pickup.*shipping|carrier.*pickup|pickup.*carrier#i'));
    }

    public function test_no_zone_rate_crud_routes_exist(): void
    {
        $this->assertSame([], $this->routesMatching('#zone[-_]?rates?#i'));
    }

    public function test_no_full_return_crud_routes_exist(): void
    {
        $rows = $this->routesMatching('#shipping.*returns?|returns?.*shipping|return-requests?#i');
        $mutating = [];
        foreach ($rows as $row) {
            $found = array_values(array_intersect($row['methods'], ['POST','PUT','PATCH','DELETE']));
            $this->assertSame([], $found, $row['uri']);
            $mutating = array_merge($mutating, $found);
        }
        $this->assertSame([], $mutating, 'No full return CRUD routes may exist.');
    }

    public function test_asserted_launch_routes_are_confirmed_by_route_listing(): void
    {
        $names = array_filter(array_column($this->routeRows(), 'name'));
        foreach (['api.admin.shipping.providers.index', 'api.admin.shipping.providers.show', 'api.admin.cod-settlements.statements.index', 'api.admin.cod-settlements.statements.show', 'api.shipping.webhook'] as $expected) {
            $this->assertContains($expected, $names);
        }
    }

    public function test_settlement_routes_enforce_finance_permissions(): void
    {
        foreach ($this->routeRows() as $row) {
            if (!str_contains($row['uri'], 'admin/cod-settlements')) {
                continue;
            }
            $this->assertContains('auth:sanctum', $row['middleware']);
            $perm = implode('|', array_filter($row['middleware'], fn ($m) => is_string($m) && str_starts_with($m, 'permission:')));
            $this->assertStringContainsString(Permission::COD_SETTLEMENT_VIEW, $perm);
        }
        SpatiePermission::firstOrCreate(['name' => Permission::COD_SETTLEMENT_VIEW, 'guard_name' => 'api']);
        Sanctum::actingAs(User::factory()->create(['type' => 'staff']));
        $this->getJson('/api/v1/admin/cod-settlements/statements')->assertForbidden();
    }

    public function test_representative_routes_expose_only_authenticated_representative_assignments(): void
    {
        $rows = $this->routesMatching('#representative|my-assignments|delivery-representative#i');
        foreach ($rows as $row) {
            $this->assertContains('auth:sanctum', $row['middleware'], $row['uri']);
            $this->assertFalse(str_contains($row['uri'], '{user}') || str_contains($row['uri'], '{representative}'), $row['uri']);
        }
        $this->assertSame([], $this->routesMatching('#admin/.+representative.+assignments#i'), 'No unscoped admin representative assignment listing.');
    }

    public function test_webhook_responses_match_approved_contract(): void
    {
        $this->assertSame(200, ShippingWebhookIngestOutcome::ACCEPTED->httpStatus());
        $this->assertSame(200, ShippingWebhookIngestOutcome::DUPLICATE->httpStatus());
        $this->assertSame(401, ShippingWebhookIngestOutcome::INVALID_SIGNATURE->httpStatus());
        $this->assertSame(400, ShippingWebhookIngestOutcome::INVALID_PAYLOAD->httpStatus());
        $this->assertSame(503, ShippingWebhookIngestOutcome::UNAVAILABLE->httpStatus());
        $this->assertSame(429, ShippingWebhookIngestOutcome::RATE_LIMITED->httpStatus());

        config([
            'shipping.providers.null.mode' => 'sandbox',
            'shipping.providers.null.modes.sandbox.enabled' => true,
            'shipping.providers.null.modes.sandbox.webhook.secret' => 'whsec_188',
            'shipping.providers.null.modes.sandbox.webhook.version' => 'v1',
            'shipping.webhook_rate_limit.max_attempts' => 60,
            'shipping.webhook_rate_limit.decay_seconds' => 60,
        ]);

        $payload = ['id' => 'evt_188_contract', 'type' => 'shipment.status_changed', 'status' => 'in_transit'];
        $raw = json_encode($payload, JSON_THROW_ON_ERROR);
        $sig = 'v1=' . hash_hmac('sha256', $raw, 'whsec_188');
        $headers = fn ($s) => $this->transformHeadersToServerVars(['Content-Type' => 'application/json', 'X-Shipping-Signature' => $s]);

        $this->call('POST', '/api/v1/general/shipping/webhooks/null', [], [], [], $headers($sig), $raw)
            ->assertStatus(200)->assertJson(['success' => true])->assertJsonPath('data.status', 'accepted')->assertJsonPath('data.event_id', 'evt_188_contract');
        $this->call('POST', '/api/v1/general/shipping/webhooks/null', [], [], [], $headers($sig), $raw)
            ->assertStatus(200)->assertJson(['success' => true])->assertJsonPath('data.status', 'duplicate');
        $this->call('POST', '/api/v1/general/shipping/webhooks/null', [], [], [], $headers('v1=' . str_repeat('0', 64)), $raw)
            ->assertStatus(401)->assertJson(['success' => false]);
    }
}
