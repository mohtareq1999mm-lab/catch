<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Services\Currency\CurrencyService;
use App\Services\Currency\UserCurrencyPreferenceService;
use Illuminate\Http\Request;

/**
 * Guest currency header — Frontend-owned via X-Currency.
 *
 * Verifies:
 *  - Header is normalized to uppercase ISO 4217.
 *  - Backend validates the value and safely falls back to catalog on
 *    invalid/malformed input without erroring ordinary API requests.
 *  - Authenticated user without preference falls back to header.
 *  - Select endpoint no longer emits guest_currency cookie.
 */
class GuestCurrencyCookieTest extends CurrencyTestCase
{
    /** @test */
    public function guest_header_value_is_normalized_to_uppercase(): void
    {
        $service = app(UserCurrencyPreferenceService::class);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => '  egp ']);

        $this->assertSame('EGP', $service->getHeaderCurrencyCode($request));
    }

    /** @test */
    public function invalid_guest_header_falls_back_to_catalog_without_error(): void
    {
        $this->seedCurrencyData();

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'XXX']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function malformed_guest_header_is_ignored(): void
    {
        $this->seedCurrencyData();

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => '12']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function header_lowercase_is_normalized(): void
    {
        $service = app(UserCurrencyPreferenceService::class);

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'kwd']);

        $this->assertSame('KWD', $service->getHeaderCurrencyCode($request));
    }

    /** @test */
    public function authenticated_user_without_preference_falls_back_to_header(): void
    {
        $this->seedCurrencyData();

        $user = $this->createAuthenticatedCustomer();

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode($user));
    }

    /** @test */
    public function select_endpoint_does_not_emit_guest_cookie_for_guests(): void
    {
        $this->seedCurrencyData();

        $response = $this->postJson(self::GENERAL_PREFIX . '/currencies/select', ['currency_code' => 'KWD']);

        $response->assertOk();
        // No guest_currency cookie should be queued or returned
        $cookies = collect($response->headers->getCookies())->pluck('name')->all();
        $this->assertNotContains('guest_currency', $cookies, 'select must not emit guest_currency cookie after header migration');
    }

    /** @test */
    public function effective_currency_resolves_from_header_when_unauthenticated(): void
    {
        $this->seedCurrencyData();

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode());
    }
}
