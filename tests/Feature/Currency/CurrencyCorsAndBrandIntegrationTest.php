<?php

declare(strict_types=1);

namespace Tests\Feature\Currency;

use App\Services\Currency\CurrencyService;
use App\Services\Currency\UserCurrencyPreferenceService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Marvel\Database\Models\Brand;
use Marvel\Database\Models\Product;

/**
 * Header-based guest currency (X-Currency) integration.
 *
 * Covers:
 *  1. JSON POST /currencies/select 200
 *  2. Invalid 422
 *  3. No guest_currency cookie required
 *  4. No HttpOnly cookie assertion (header is stateless)
 *  5. CORS explicit origin / no wildcard / X-Currency allowed
 *  6. OPTIONS preflight
 *  7. Guest header resolved
 *  8. Auth pref overrides header
 *  9. Brand products conversion + metadata
 */
class CurrencyCorsAndBrandIntegrationTest extends CurrencyTestCase
{
    /** @test */
    public function json_post_to_select_returns_200(): void
    {
        $this->seedCurrencyData();

        $response = $this->postJson(self::GENERAL_PREFIX . '/currencies/select', [
            'currency_code' => 'KWD',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.code', 'KWD');
        // No guest_currency cookie
        $this->assertNotContains('guest_currency', collect($response->headers->getCookies())->pluck('name')->all());
    }

    /** @test */
    public function json_post_with_lowercase_is_normalized_and_accepted(): void
    {
        $this->seedCurrencyData();

        $response = $this->postJson(self::GENERAL_PREFIX . '/currencies/select', [
            'currency_code' => 'kwd',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.code', 'KWD');
    }

    /** @test */
    public function form_data_post_to_select_still_works(): void
    {
        $this->seedCurrencyData();

        $response = $this->post(self::GENERAL_PREFIX . '/currencies/select', [
            'currency_code' => 'KWD',
        ], ['Accept' => 'application/json']);

        $response->assertOk();
        $response->assertJsonPath('data.code', 'KWD');
    }

    /** @test */
    public function invalid_currency_returns_422(): void
    {
        $this->seedCurrencyData();

        $response = $this->postJson(self::GENERAL_PREFIX . '/currencies/select', [
            'currency_code' => 'XXX',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['currency_code']);
    }

    /** @test */
    public function select_does_not_set_guest_currency_cookie(): void
    {
        $this->seedCurrencyData();

        $response = $this->postJson(self::GENERAL_PREFIX . '/currencies/select', ['currency_code' => 'KWD']);
        $response->assertOk();
        $cookies = collect($response->headers->getCookies())->pluck('name')->all();
        $this->assertNotContains('guest_currency', $cookies);
    }

    /** @test */
    public function guest_header_is_resolved_to_effective_currency(): void
    {
        $this->seedCurrencyData();

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode());

        // Lowercase normalization
        $request2 = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'sar']);
        $this->assertSame('SAR', app(UserCurrencyPreferenceService::class)->getHeaderCurrencyCode($request2));
    }

    /** @test */
    public function missing_header_falls_back_to_catalog(): void
    {
        $this->seedCurrencyData();
        $this->app->forgetInstance(CurrencyService::class);
        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function unknown_and_inactive_header_falls_back(): void
    {
        $this->seedCurrencyData();

        // Unknown
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'XXX']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);
        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());

        // Inactive
        $this->createCurrency('AED', ['is_active' => false]);
        $request2 = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'AED']);
        $this->app->instance('request', $request2);
        $this->app->forgetInstance(CurrencyService::class);
        $this->assertSame('USD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function authenticated_preference_overrides_header(): void
    {
        $this->seedCurrencyData();

        $user = $this->createCustomer();
        app(UserCurrencyPreferenceService::class)->setUserPreference($user, 'KWD');

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'SAR']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode($user));
    }

    /** @test */
    public function authenticated_without_preference_uses_header(): void
    {
        $this->seedCurrencyData();

        $user = $this->createCustomer();

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'SAR']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('SAR', app(CurrencyService::class)->getEffectiveCode($user));
    }

    /** @test */
    public function cors_with_credentials_returns_explicit_allowed_origin(): void
    {
        $this->seedCurrencyData();

        config([
            'cors.allowed_origins' => ['http://localhost:3000'],
            'cors.supports_credentials' => true,
            'cors.allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'lang', 'x-channel', 'X-Currency'],
            'cors.allowed_methods' => ['*'],
            'cors.paths' => ['api/*'],
        ]);

        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])->getJson(self::GENERAL_PREFIX . '/currencies');

        $response->assertOk();
        $this->assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $this->assertNotSame('*', $response->headers->get('Access-Control-Allow-Origin'));
    }

    /** @test */
    public function cors_does_not_return_wildcard_when_credentials_enabled(): void
    {
        config([
            'cors.allowed_origins' => ['http://localhost:3000'],
            'cors.supports_credentials' => true,
            'cors.paths' => ['api/*'],
        ]);

        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])->options(self::GENERAL_PREFIX . '/currencies/select', [], [
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Content-Type, Authorization, X-Currency',
        ]);

        $allowOrigin = $response->headers->get('Access-Control-Allow-Origin');
        $this->assertNotSame('*', $allowOrigin);
        if ($allowOrigin !== null) {
            $this->assertSame('http://localhost:3000', $allowOrigin);
        }
    }

    /** @test */
    public function options_preflight_returns_correct_cors_headers(): void
    {
        config([
            'cors.allowed_origins' => ['http://localhost:3000'],
            'cors.supports_credentials' => true,
            'cors.allowed_headers' => ['Content-Type', 'Accept', 'Authorization', 'lang', 'x-channel', 'X-Currency'],
            'cors.allowed_methods' => ['*'],
            'cors.paths' => ['api/*'],
        ]);

        $response = $this->withHeaders(['Origin' => 'http://localhost:3000'])->call('OPTIONS', self::GENERAL_PREFIX . '/currencies/select', [], [], [], [
            'HTTP_ORIGIN' => 'http://localhost:3000',
            'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
            'HTTP_ACCESS_CONTROL_REQUEST_HEADERS' => 'Content-Type, Authorization, lang, x-channel, X-Currency',
        ]);

        $this->assertContains($response->getStatusCode(), [200, 204]);
        $this->assertSame('http://localhost:3000', $response->headers->get('Access-Control-Allow-Origin'));
        $this->assertSame('true', $response->headers->get('Access-Control-Allow-Credentials'));
        $allowHeaders = $response->headers->get('Access-Control-Allow-Headers');
        $this->assertNotNull($allowHeaders);
        foreach (['Content-Type', 'Authorization', 'lang', 'x-channel', 'X-Currency'] as $h) {
            $this->assertTrue(stripos($allowHeaders, $h) !== false, "Allow-Headers must contain $h");
        }
    }

    /** @test */
    public function guest_header_is_resolved_from_header(): void
    {
        $this->seedCurrencyData();

        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);

        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function logout_does_not_require_guest_cookie(): void
    {
        $this->seedCurrencyData();

        $user = $this->createAuthenticatedCustomer();
        // Header present before logout
        $request = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request);
        $this->app->forgetInstance(CurrencyService::class);
        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode($user));

        $response = $this->postJson('/api/v1/logout');
        $response->assertOk();

        // After logout, header still resolves (guest)
        $request2 = Request::create('/test', 'GET', [], [], [], ['HTTP_X_CURRENCY' => 'KWD']);
        $this->app->instance('request', $request2);
        $this->app->forgetInstance(CurrencyService::class);
        $this->assertSame('KWD', app(CurrencyService::class)->getEffectiveCode());
    }

    /** @test */
    public function brand_products_use_effective_currency_conversion(): void
    {
        $this->seedCurrencyData();

        $brand = Brand::create(['name' => ['en' => 'Test Brand'], 'slug' => 'test-brand-'.Str::uuid(), 'status' => 1]);
        $product = Product::create([
            'name' => ['en' => 'Brand Currency Product'], 'slug' => 'brand-currency-product-'.Str::uuid(),
            'price' => 100.0, 'status' => true, 'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0,
        ]);
        $product->brands()->attach($brand->id);

        $this->app->forgetInstance(CurrencyService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $response = $this->call('GET', self::GENERAL_PREFIX . '/brands/'.$brand->slug, [], [], [], ['HTTP_ACCEPT'=>'application/json']);
        $response->assertOk();
        $productData = $response->json('data.products.0');
        $this->assertEqualsWithDelta(100.0, $productData['price'], 0.01);
        $this->assertSame('USD', $productData['currency']['code']);

        $this->app->forgetInstance(CurrencyService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $response2 = $this->call('GET', self::GENERAL_PREFIX . '/brands/'.$brand->slug, [], [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_CURRENCY'=>'SAR']);
        $response2->assertOk();
        $productData2 = $response2->json('data.products.0');
        $this->assertEqualsWithDelta(375.0, $productData2['price'], 0.01);
        $this->assertSame('SAR', $productData2['currency']['code']);

        $this->app->forgetInstance(CurrencyService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $response3 = $this->call('GET', self::GENERAL_PREFIX . '/brands/'.$brand->slug, [], [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_CURRENCY'=>'KWD']);
        $response3->assertOk();
        $productData3 = $response3->json('data.products.0');
        $this->assertEqualsWithDelta(22.1, $productData3['price'], 0.01);
        $this->assertSame('KWD', $productData3['currency']['code']);
    }

    /** @test */
    public function brand_products_expose_currency_metadata_consistently(): void
    {
        $this->seedCurrencyData();

        $brand = Brand::create(['name' => ['en' => 'Meta Brand'], 'slug' => 'meta-brand-'.Str::uuid(), 'status' => 1]);
        $product = Product::create([
            'name' => ['en' => 'Meta Product'], 'slug' => 'meta-product-'.Str::uuid(),
            'price' => 50.0, 'status' => true, 'in_stock' => true, 'stock_quantity' => 5, 'reserved_quantity' => 0,
        ]);
        $product->brands()->attach($brand->id);

        $this->app->forgetInstance(CurrencyService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $response = $this->call('GET', self::GENERAL_PREFIX . '/brands/'.$brand->slug, [], [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_CURRENCY'=>'KWD']);
        $response->assertOk();
        $brandProduct = $response->json('data.products.0');
        $this->assertArrayHasKey('currency', $brandProduct);
        $this->assertSame('KWD', $brandProduct['currency']['code']);
        foreach (['id','code','name','symbol','icon'] as $k) {
            $this->assertArrayHasKey($k, $brandProduct['currency']);
        }

        $this->app->forgetInstance(CurrencyService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $productResponse = $this->call('GET', self::GENERAL_PREFIX . '/products/'.$product->slug, [], [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_CURRENCY'=>'KWD']);
        $productResponse->assertOk();
        $productCurrency = $productResponse->json('data.currency');
        $this->assertSame($productCurrency['code'], $brandProduct['currency']['code']);
    }

    /** @test */
    public function brand_products_cache_is_currency_aware(): void
    {
        $this->seedCurrencyData();

        $brand = Brand::create(['name' => ['en' => 'Cache Brand'], 'slug' => 'cache-brand-'.Str::uuid(), 'status' => 1]);
        $product = Product::create([
            'name' => ['en' => 'Cache Brand Product'], 'slug' => 'cache-brand-product-'.Str::uuid(),
            'price' => 100.0, 'status' => true, 'in_stock' => true, 'stock_quantity' => 10, 'reserved_quantity' => 0,
        ]);
        $product->brands()->attach($brand->id);

        $this->app->forgetInstance(CurrencyService::class);
        \Illuminate\Support\Facades\Cache::flush();
        $this->call('GET', self::GENERAL_PREFIX . '/brands-products?limit=10&limit_brand=10', [], [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_CURRENCY'=>'KWD'])->assertOk();

        $this->app->forgetInstance(CurrencyService::class);
        $responseSar = $this->call('GET', self::GENERAL_PREFIX . '/brands-products?limit=10&limit_brand=10', [], [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_CURRENCY'=>'SAR'])->assertOk();
        $firstSar = $responseSar->json('data.0');
        if ($firstSar) $this->assertEqualsWithDelta(375.0, $firstSar['price'], 0.01);

        $this->app->forgetInstance(CurrencyService::class);
        $responseKwd = $this->call('GET', self::GENERAL_PREFIX . '/brands-products?limit=10&limit_brand=10', [], [], [], ['HTTP_ACCEPT'=>'application/json','HTTP_X_CURRENCY'=>'KWD'])->assertOk();
        $firstKwd = $responseKwd->json('data.0');
        if ($firstKwd) $this->assertEqualsWithDelta(22.1, $firstKwd['price'], 0.01);
    }
}
