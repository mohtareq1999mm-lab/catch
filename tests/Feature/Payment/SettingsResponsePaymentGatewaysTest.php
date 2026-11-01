<?php

declare(strict_types=1);

namespace Tests\Feature\Payment;

use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Settings;
use Tests\Feature\Currency\CurrencyTestCase;

/**
 * Settings-response integration (second pass): the Site Settings GET
 * responses (public /api/v1/general/settings AND admin /api/v1/settings,
 * both rendered by Marvel\Http\Resources\SettingResource) must carry the
 * compact per-gateway availability block {payment_gateways: {code:
 * {enabled, available}}} built from the SAME effective settings the
 * checkout authority uses.
 *
 * Enforced end to end here: dashboard toggle (PUT /api/v1/admin/payment-gateways/{code})
 * → tagged cache flush → both settings responses reflect the new state.
 */
class SettingsResponsePaymentGatewaysTest extends CurrencyTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->seedCurrencyData();

        // Hermetic baseline regardless of the developer .env.
        config(['payment.gateways.myfatoorah.api_key' => 'test-key']);
        config(['payment.gateways.myfatoorah.supported_currencies' => ['KWD']]);
        config(['payment.gateways.stripe.secret_key' => null]);
        config(['payment.gateways.stripe.webhook_secret' => null]);
        config(['payment.gateways.paypal.client_secret' => null]);
        config(['payment.gateways.tap.secret_key' => null]);
        config(['payment.gateways.tabby.secret_key' => null]);
        config(['payment.gateways.tamara.api_token' => null]);
        config(['payment.gateways.tamara.notification_token' => null]);
        // Currency lists are env-backed: pin the ones these tests reason about.
        config(['payment.gateways.tap.supported_currencies' => ['AED', 'KWD', 'USD']]);
        config(['payment.gateways.tap.secret_key' => 'test-tap-secret']);
    }

    /** @test */
    public function general_settings_response_contains_gateway_availability_map(): void
    {
        // Canary value must be present in config BEFORE the request: if it
        // ever leaks into the payload the assertion fires even under a
        // renamed key. (Currencies are already seeded by setUp.)
        config(['payment.gateways.myfatoorah.api_key' => 'CANARY-MYFATOORAH-SECRET']);

        $response = $this->getJson('/api/v1/general/settings');

        $response->assertOk()->assertJson(['success' => true]);

        $map = $response->json('data.payment_gateways');
        foreach (['stripe', 'myfatoorah', 'tap', 'tabby', 'tamara'] as $code) {
            $this->assertArrayHasKey($code, $map, "[$code] missing from settings response");
            $this->assertSame(['enabled', 'available'], array_keys($map[$code]), "[$code] shape");
            $this->assertIsBool($map[$code]['enabled']);
            $this->assertIsBool($map[$code]['available']);
        }

        // Currency/credential verdicts on the seeded USD catalog:
        // myfatoorah: enabled (default true) + configured (canary key is
        // truthy) BUT KWD-only currency list → available=false.
        $this->assertTrue($map['myfatoorah']['enabled']);
        $this->assertFalse($map['myfatoorah']['available']);
        // tap: NOT enabled by default (settings row has no override) despite
        // being configured → available=false.
        $this->assertFalse($map['tap']['enabled']);
        $this->assertFalse($map['tap']['available']);

        // No ever-secret key or canary value may appear anywhere.
        $body = strtolower($response->getContent());
        foreach (['secret_key', 'api_key', 'client_secret', 'webhook_secret', 'canary-myfatoorah-secret', 'test-tap-secret'] as $needle) {
            $this->assertStringNotContainsString($needle, $body);
        }

        // Backward compatibility: the pre-existing payload is untouched.
        $data = $response->json('data');
        $this->assertArrayHasKey('options', $data);
        $this->assertArrayHasKey('site_name', $data);
        $this->assertArrayHasKey('currency_selection_enabled', $data);
    }

    /** @test */
    public function admin_settings_response_requires_auth_and_permission_and_carries_the_map(): void
    {
        // Guest → 401 (auth:sanctum).
        $this->getJson('/api/v1/settings')->assertStatus(401);

        // Authenticated admin WITHOUT the settings permissions → 403.
        $noPerm = $this->createUserWithPermissions([], 'admin');
        Sanctum::actingAs($noPerm);
        $this->getJson('/api/v1/settings')->assertStatus(403);

        // view-settings alone satisfies the view-settings|update-settings OR gate.
        $viewer = $this->createUserWithPermissions(['view-settings'], 'admin');
        Sanctum::actingAs($viewer);
        $response = $this->getJson('/api/v1/settings')->assertStatus(200)->assertJson(['success' => true]);

        $map = $response->json('data.payment_gateways');
        foreach (['stripe', 'myfatoorah', 'tap', 'tabby', 'tamara'] as $code) {
            $this->assertArrayHasKey($code, $map, "[$code] missing from admin settings response");
            $this->assertSame(['enabled', 'available'], array_keys($map[$code]));
        }

        // update-settings ALONE is intentionally denied on GET: the controller
        // ALSO requires view-settings for index (constructor middleware), so
        // the effective read rule is the route OR gate AND the constructor
        // view.requirement — i.e. view-settings is mandatory to read. The
        // write path honors update-settings alone (tested below).
        $updater = $this->createUserWithPermissions(['update-settings'], 'admin');
        Sanctum::actingAs($updater);
        $this->getJson('/api/v1/settings')->assertStatus(403);

        $this->assertStringNotContainsString('test-tap-secret', strtolower($response->getContent()));
        $this->assertStringNotContainsString('test-key', strtolower($response->getContent()));
    }

    /** @test */
    public function dashboard_gateway_toggle_reflects_on_both_settings_endpoints(): void
    {
        $admin = $this->createUserWithPermissions(['view-settings', 'update-settings'], 'admin');
        Sanctum::actingAs($admin);

        $frontMap = fn () => $this->getJson('/api/v1/general/settings')->json('data.payment_gateways');
        $adminMap = fn () => $this->getJson('/api/v1/settings')->json('data.payment_gateways');

        // Baseline: tap not enabled (fresh settings row, no overrides).
        $this->assertFalse($frontMap()['tap']['enabled']);
        $this->assertFalse($frontMap()['tap']['available']);

        // Dashboard toggle ON through the REAL admin API (flushes the cached
        // settings payload): tap is configured hermetically and supports the
        // USD catalog, so available must follow enabled=true.
        $this->putJson('/api/v1/admin/payment-gateways/tap', ['enabled' => true])->assertStatus(200);

        $front = $frontMap();
        $this->assertTrue($front['tap']['enabled']);
        $this->assertTrue($front['tap']['available']);
        $this->assertSame($front['tap']['enabled'], $adminMap()['tap']['enabled']);
        $this->assertSame($front['tap']['available'], $adminMap()['tap']['available']);

        // Dashboard toggle OFF: both endpoints reflect it immediately.
        $this->putJson('/api/v1/admin/payment-gateways/tap', ['enabled' => false])->assertStatus(200);

        $front = $frontMap();
        $this->assertFalse($front['tap']['enabled']);
        $this->assertFalse($front['tap']['available']);
        $this->assertFalse($adminMap()['tap']['enabled']);
    }

    /** @test */
    public function settings_put_controls_gateway_enabled_flags(): void
    {
        // Guest → 401 (auth:sanctum, no permission evaluation yet).
        $this->putJson('/api/v1/settings', ['payment_gateways' => ['tap' => ['enabled' => true]]])
            ->assertStatus(401);

        // update-settings alone suffices to WRITE settings (constructor pins
        // update-settings for update; no view-settings requirement there).
        $writer = $this->createUserWithPermissions(['update-settings'], 'admin');
        Sanctum::actingAs($writer);

        // A user WITHOUT the permission → 403
        // (re-assert at the PUT surface, not just the GET surface).
        $noPerm = $this->createUserWithPermissions([], 'admin');
        Sanctum::actingAs($noPerm);
        $this->putJson('/api/v1/settings', ['payment_gateways' => ['tap' => ['enabled' => true]]])
            ->assertStatus(403);

        Sanctum::actingAs($writer);

        $frontMap = fn () => $this->getJson('/api/v1/general/settings')->json('data.payment_gateways');
        $this->assertFalse($frontMap()['tap']['enabled']);

        // Toggle ON through the SAME endpoint the dashboard settings page
        // uses. The PUT response itself carries the refreshed block.
        $response = $this->putJson('/api/v1/settings', ['payment_gateways' => ['tap' => ['enabled' => true]]])
            ->assertStatus(200)
            ->assertJson(['success' => true]);

        $this->assertTrue($response->json('data.payment_gateways.tap.enabled'));
        $front = $frontMap();
        $this->assertTrue($front['tap']['enabled']);
        // Configured tap + USD catalog → availability follows the toggle.
        $this->assertTrue($front['tap']['available']);

        // Unknown gateway code → 422 AND nothing persisted (all-or-nothing):
        // tap must stay enabled even though the payload also asked for it.
        $this->putJson('/api/v1/settings', [
            'payment_gateways' => [
                'tap' => ['enabled' => true],
                'no-such-gateway' => ['enabled' => true],
            ],
        ])->assertStatus(422);

        $front = $frontMap();
        // tap stays whatever it was before the failed call.
        $this->assertTrue($front['tap']['enabled']);

        // Malformed boolean → 422 (SettingsRequest validation).
        $this->putJson('/api/v1/settings', ['payment_gateways' => ['tap' => ['enabled' => 'maybe']]])
            ->assertStatus(422);

        // `available` is a derived verdict, NOT writable: sending it must be
        // ignored. tabby is enabled but UNCONFIGURED → available stays false
        // no matter what the payload claims.
        $this->putJson('/api/v1/settings', [
            'payment_gateways' => ['tabby' => ['enabled' => true, 'available' => true]],
        ])->assertStatus(200);

        $front = $frontMap();
        $this->assertTrue($front['tabby']['enabled']);
        $this->assertFalse($front['tabby']['available']);

        // Toggle OFF through the same endpoint: reflected immediately.
        $this->putJson('/api/v1/settings', ['payment_gateways' => ['tap' => ['enabled' => false]]])
            ->assertStatus(200);
        $front = $frontMap();
        $this->assertFalse($front['tap']['enabled']);
        $this->assertFalse($front['tap']['available']);
    }

    /** @test */
    public function general_settings_save_without_gateways_block_preserves_toggles(): void
    {
        $writer = $this->createUserWithPermissions(['update-settings'], 'admin');
        Sanctum::actingAs($writer);

        $this->putJson('/api/v1/settings', ['payment_gateways' => ['tap' => ['enabled' => true]]])
            ->assertStatus(200);

        // A full settings save that does NOT mention gateways (typical
        // dashboard payload carrying its own options subset) must not
        // silently drop the stored gateway toggles.
        $this->putJson('/api/v1/settings', [
            'site_name' => ['en' => 'Test Site', 'ar' => 'موقع'],
            'options' => ['currency' => 'USD'],
        ])->assertStatus(200);

        $viewer = $this->createUserWithPermissions(['view-settings'], 'admin');
        Sanctum::actingAs($viewer);

        $map = $this->getJson('/api/v1/general/settings')->json('data.payment_gateways');
        $this->assertTrue($map['tap']['enabled']);
        $this->assertTrue($map['tap']['available']);
    }

    /** @test */
    public function settings_response_survives_a_missing_settings_row(): void
    {
        // DELETE (not TRUNCATE — TRUNCATE is DDL and would implicitly commit
        // the test transaction on MySQL): resource renders its null invariant.
        Settings::query()->delete();

        $response = $this->getJson('/api/v1/general/settings');

        $response->assertOk()->assertJson(['success' => true]);

        // The availability block is config-driven and still present.
        $map = $response->json('data.payment_gateways');
        foreach (['stripe', 'myfatoorah', 'tap', 'tabby', 'tamara'] as $code) {
            $this->assertArrayHasKey($code, $map, "[$code]");
        }
        // myfatoorah keeps its default enabled=true (config), and stays
        // unavailable because it is configured for KWD only and the catalog
        // falls back without a settings row.
        $this->assertFalse($map['myfatoorah']['available']);
        $this->assertNull($response->json('data.options'));
    }
}
