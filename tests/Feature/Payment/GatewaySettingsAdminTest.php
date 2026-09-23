<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use App\Audit\ActivitySnapshot;
use App\Jobs\LogActivityJob;
use App\Services\Payment\GatewaySettingsService;
use App\Services\Payment\PaymentGatewayRegistry;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Settings;
use Tests\Feature\Currency\CurrencyTestCase;

class GatewaySettingsAdminTest extends CurrencyTestCase
{
    private const ADMIN_PREFIX = '/api/v1/admin/payment-gateways';

    private const PERMISSIONS = ['view-settings', 'update-settings'];

    private const SECRET_KEYS = ['secret_key', 'client_secret', 'api_key', 'webhook_secret'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);
        config(['payment.gateways.stripe.secret_key' => null]);
        config(['payment.gateways.stripe.webhook_secret' => null]);
        config(['payment.gateways.paypal.client_secret' => null]);
    }

    private function actingAdmin(array $permissions = self::PERMISSIONS)
    {
        $admin = $this->createUserWithPermissions($permissions, 'admin');
        Sanctum::actingAs($admin);

        return $admin;
    }

    /**
     * @return array<string, array>
     */
    private function gatewayOptions(): array
    {
        $options = Settings::query()->first()?->options ?? [];
        $gateways = $options['payment_gateways'] ?? [];

        return is_array($gateways) ? $gateways : [];
    }

    /** @test */
    public function list_shape_sorted_and_catalog_support_flags(): void
    {
        $this->actingAdmin();

        $response = $this->getJson(self::ADMIN_PREFIX);

        $response->assertStatus(200)->assertJson(['success' => true]);
        $this->assertSame('USD', $response->json('data.catalog_currency'));

        $gateways = $response->json('data.gateways');
        $this->assertCount(3, $gateways);

        foreach ($gateways as $row) {
            $this->assertArrayHasKey('code', $row);
            $this->assertArrayHasKey('display_name', $row);
            $this->assertArrayHasKey('enabled', $row);
            $this->assertArrayHasKey('configured', $row);
            $this->assertArrayHasKey('supported_currencies', $row);
            $this->assertArrayHasKey('methods', $row);
            $this->assertArrayHasKey('sort_order', $row);
            $this->assertArrayHasKey('supports_catalog_currency', $row);
            $this->assertIsBool($row['enabled']);
            $this->assertIsBool($row['configured']);
            $this->assertIsBool($row['supports_catalog_currency']);
        }

        $orders = array_column($gateways, 'sort_order');
        $sorted = $orders;
        sort($sorted);
        $this->assertSame($sorted, $orders);

        $byCode = collect($gateways)->keyBy('code');
        $this->assertArrayHasKey('myfatoorah', $byCode);
        $this->assertArrayHasKey('stripe', $byCode);
        $this->assertArrayHasKey('paypal', $byCode);

        // MyFatoorah's env list (KWD..EGP) does not cover the USD catalog.
        $this->assertFalse($byCode['myfatoorah']['supports_catalog_currency']);
        $this->assertTrue($byCode['stripe']['supports_catalog_currency']);

        // Hermetic api key => myfatoorah adapter reports configured.
        $this->assertTrue($byCode['myfatoorah']['configured']);
        $this->assertTrue($byCode['myfatoorah']['enabled']);
    }

    /** @test */
    public function update_display_name_and_sort_order_persist_and_resort(): void
    {
        $this->actingAdmin();

        $this->putJson(self::ADMIN_PREFIX . '/paypal', [
            'display_name' => 'PayPal Express',
            'sort_order' => 1,
        ])->assertStatus(200)->assertJson([
            'success' => true,
            'data' => ['code' => 'paypal', 'display_name' => 'PayPal Express', 'sort_order' => 1],
        ]);

        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['sort_order' => 5])->assertStatus(200);
        $this->putJson(self::ADMIN_PREFIX . '/stripe', ['sort_order' => 9])->assertStatus(200);

        $options = $this->gatewayOptions();
        $this->assertSame('PayPal Express', $options['paypal']['display_name']);
        $this->assertSame(1, $options['paypal']['sort_order']);

        $codes = collect($this->getJson(self::ADMIN_PREFIX)->json('data.gateways'))->pluck('code')->all();
        $this->assertSame(['paypal', 'myfatoorah', 'stripe'], $codes);
    }

    /** @test */
    public function disable_myfatoorah_persists_and_blocks_initiate_then_reenable_restores(): void
    {
        $this->actingAdmin();

        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['enabled' => false])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['code' => 'myfatoorah', 'enabled' => false]]);

        $this->assertFalse($this->gatewayOptions()['myfatoorah']['enabled']);
        $this->assertFalse(app(GatewaySettingsService::class)->isEnabled('myfatoorah'));

        $gate = app(PaymentGatewayRegistry::class)->canInitiate('myfatoorah', 'online', 'KWD');
        $this->assertFalse($gate['ok']);
        $this->assertSame('disabled', $gate['reason']);

        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['enabled' => true])
            ->assertStatus(200)
            ->assertJson(['success' => true, 'data' => ['code' => 'myfatoorah', 'enabled' => true]]);

        $gate = app(PaymentGatewayRegistry::class)->canInitiate('myfatoorah', 'online', 'KWD');
        $this->assertTrue($gate['ok']);
        $this->assertSame('ok', $gate['reason']);
    }

    /** @test */
    public function unknown_code_returns_404(): void
    {
        $this->actingAdmin();

        $this->putJson(self::ADMIN_PREFIX . '/no-such-gateway', ['enabled' => false])
            ->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /** @test */
    public function validation_errors_return_422(): void
    {
        $this->actingAdmin();

        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['enabled' => 'not-a-boolean'])->assertStatus(422);
        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['display_name' => str_repeat('a', 61)])->assertStatus(422);
        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['sort_order' => 'first'])->assertStatus(422);
        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['sort_order' => -1])->assertStatus(422);
    }

    /** @test */
    public function unauthenticated_requests_are_rejected_with_401(): void
    {
        $this->getJson(self::ADMIN_PREFIX)->assertStatus(401);
        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['enabled' => false])->assertStatus(401);
    }

    /** @test */
    public function user_without_settings_permission_is_forbidden(): void
    {
        $user = $this->createUserWithPermissions([], 'admin');
        Sanctum::actingAs($user);

        $this->getJson(self::ADMIN_PREFIX)->assertStatus(403);
        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['enabled' => false])->assertStatus(403);

        $customer = $this->createCustomer();
        Sanctum::actingAs($customer);

        $this->getJson(self::ADMIN_PREFIX)->assertStatus(403);
        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['enabled' => false])->assertStatus(403);
    }

    /** @test */
    public function responses_never_contain_secrets_and_secrets_are_never_stored(): void
    {
        config([
            'payment.gateways.myfatoorah.api_key' => 'live-api-key-abc123',
            'payment.gateways.stripe.secret_key' => 'sk-live-xyz789',
            'payment.gateways.stripe.webhook_secret' => 'whsec-live-456',
            'payment.gateways.paypal.client_secret' => 'paypal-live-secret-000',
        ]);
        $this->actingAdmin();

        $listBody = $this->getJson(self::ADMIN_PREFIX)->assertStatus(200)->getContent();
        foreach (['live-api-key-abc123', 'sk-live-xyz789', 'whsec-live-456', 'paypal-live-secret-000'] as $secret) {
            $this->assertStringNotContainsString($secret, $listBody);
        }
        foreach (self::SECRET_KEYS as $key) {
            $this->assertStringNotContainsString($key, $listBody);
        }

        // Attempt to inject secrets / config-only fields alongside a legit change.
        $updateBody = $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', [
            'enabled' => true,
            'api_key' => 'INJECTED',
            'secret_key' => 'INJECTED',
            'client_secret' => 'INJECTED',
            'webhook_secret' => 'INJECTED',
            'supported_currencies' => ['XXX'],
            'methods' => ['magic'],
            'class' => 'Evil\\Gateway',
        ])->assertStatus(200)->getContent();

        $this->assertStringNotContainsString('INJECTED', $updateBody);
        foreach (self::SECRET_KEYS as $key) {
            $this->assertStringNotContainsString($key, $updateBody);
        }

        $stored = $this->gatewayOptions()['myfatoorah'] ?? [];
        foreach (self::SECRET_KEYS as $key) {
            $this->assertArrayNotHasKey($key, $stored);
        }
        $this->assertArrayNotHasKey('class', $stored);
        $this->assertArrayNotHasKey('supported_currencies', $stored);
        $this->assertArrayNotHasKey('methods', $stored);

        // Config-only fields keep their env values despite the injection attempt.
        $row = app(GatewaySettingsService::class)->adminRow('myfatoorah');
        $this->assertNotContains('XXX', $row['supported_currencies']);
        $this->assertNotContains('magic', $row['methods']);
        $this->assertTrue($row['enabled']);
    }

    /** @test */
    public function update_dispatches_audit_job_with_snapshot(): void
    {
        $this->actingAdmin();
        Bus::fake();

        $this->putJson(self::ADMIN_PREFIX . '/myfatoorah', ['enabled' => false])->assertStatus(200);

        Bus::assertDispatched(LogActivityJob::class, function (LogActivityJob $job) {
            return $job->snapshot instanceof ActivitySnapshot
                && $job->snapshot->logName === 'settings'
                && $job->snapshot->event === 'payment_gateway_updated'
                && ($job->snapshot->context['gateway'] ?? null) === 'myfatoorah'
                && ($job->snapshot->old['enabled'] ?? null) === true
                && ($job->snapshot->new['enabled'] ?? null) === false;
        });
    }

    /** @test */
    public function update_writes_activity_log_row_on_sync_queue(): void
    {
        $this->actingAdmin();

        $this->putJson(self::ADMIN_PREFIX . '/stripe', ['display_name' => 'Stripe Cards'])->assertStatus(200);

        $this->assertDatabaseHas('activity_log', [
            'log_name' => 'settings',
            'event' => 'payment_gateway_updated',
        ]);
    }

    /** @test */
    public function read_merge_ignores_non_allowlisted_override_keys(): void
    {
        // SHOULD-FIX (a): even a stale/hostile settings row carrying secrets,
        // class, currencies or methods must not leak into the merged
        // definition — only {enabled, display_name, sort_order} are read.
        $settings = $this->createSettings();
        $options = is_array($settings->options) ? $settings->options : [];
        $options['payment_gateways'] = ['myfatoorah' => [
            'enabled' => false,
            'display_name' => 'Custom',
            'sort_order' => 7,
            'class' => 'Evil\\Adapter',
            'supported_currencies' => ['XXX'],
            'methods' => ['evil'],
            'api_key' => 'leaked?',
        ]];
        $settings->options = $options;
        $settings->save();

        $service = app(GatewaySettingsService::class);
        $definition = $service->definition('myfatoorah');

        $this->assertNotNull($definition);
        $this->assertFalse($service->isEnabled('myfatoorah'));
        $this->assertSame('Custom', $definition['display_name']);
        $this->assertSame(7, $definition['sort_order']);

        // Config-only fields keep their env values despite the row.
        $this->assertSame(config('payment.gateways.myfatoorah.class'), $definition['class']);
        $this->assertSame(config('payment.gateways.myfatoorah.supported_currencies'), $definition['supported_currencies']);
        $this->assertSame(config('payment.gateways.myfatoorah.methods'), $definition['methods']);
        // The row's injected api_key must not override the env-backed one.
        $this->assertSame(config('payment.gateways.myfatoorah.api_key'), $definition['api_key']);
        $this->assertNotSame('leaked?', $definition['api_key'] ?? null);
    }
}
