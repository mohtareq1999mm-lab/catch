<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Marvel\Database\Models\SocialLoginCode;
use Mockery;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Route-level contract tests for the four social login routes.
 *
 * Scope is deliberately narrow: route resolution, the configured canonical
 * callback URL (host-independent), the frontend redirect destination,
 * invalid-provider handling and the existing exchange contract.
 */
class SocialRoutesCallbackContractTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private const FRONTEND_URL = 'https://meem-market-ecommerce.vercel.app';

    /** Deliberately NOT the test request host — proves host-independence. */
    private const CALLBACK_URL = 'https://oauth.example.test/api/v1/social/google/callback';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        config([
            'services.google.client_id' => 'test-client-id.apps.googleusercontent.com',
            'services.google.client_secret' => 'test-client-secret',
            'services.google.redirect' => self::CALLBACK_URL,
            'shop.social_login.frontend_url' => self::FRONTEND_URL,
            'shop.social_login.code_ttl_minutes' => 10,
        ]);
    }

    /**
     * Parse a query string out of an authorization URL.
     */
    private function authUrlParams(string $url): array
    {
        parse_str((string) parse_url($url, PHP_URL_QUERY), $params);

        return $params;
    }

    // ========================================================================
    // 1–4. Route resolution
    // ========================================================================

    public function test_social_redirect_resolves_to_redirect_from_query_not_provider_wildcard(): void
    {
        // A 200 with success:true proves the literal alias route won: if
        // /social/redirect were captured by /social/{provider}, the provider
        // would be the literal string "redirect" and validation would fail
        // with a 500.
        $response = $this->get(self::PREFIX . '/social/redirect?provider=google');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertStringStartsWith('https://accounts.google.com', $response->json('url'));
    }

    public function test_social_google_route_resolves_to_redirect(): void
    {
        $response = $this->get(self::PREFIX . '/social/google');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('url'));
    }

    public function test_social_exchange_route_resolves_to_exchange(): void
    {
        // A known-invalid code returns the exchange contract's 400 envelope;
        // a 404 would mean the route did not resolve.
        $response = $this->postJson(self::PREFIX . '/social/exchange', [
            'code' => bin2hex(random_bytes(32)),
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Invalid or expired authorization code.');
    }

    public function test_social_callback_route_resolves_to_callback(): void
    {
        // Mock the provider so no real outbound request to Google occurs;
        // a thrown user() failure exercises the existing failure-redirect
        // contract. A 404 would mean the route did not resolve.
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \Exception('no_code'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(self::PREFIX . '/social/google/callback');

        $response->assertStatus(302);
        $this->assertSame(
            self::FRONTEND_URL . '/auth?error=social_login_failed',
            $response->headers->get('Location')
        );
    }

    // ========================================================================
    // 5–7. Canonical callback URL behavior
    // ========================================================================

    public function test_authorization_url_contains_configured_callback_url(): void
    {
        $url = $this->get(self::PREFIX . '/social/google')->json('url');
        $params = $this->authUrlParams($url);

        $this->assertSame(self::CALLBACK_URL, $params['redirect_uri']);
        $this->assertSame('test-client-id.apps.googleusercontent.com', $params['client_id']);
        $this->assertSame('code', $params['response_type']);
    }

    public function test_changing_redirect_configuration_changes_the_authorization_url(): void
    {
        $urlA = $this->get(self::PREFIX . '/social/google')->json('url');
        $paramsA = $this->authUrlParams($urlA);

        config(['services.google.redirect' => 'https://project-b.example.test/api/v1/social/google/callback']);

        $urlB = $this->get(self::PREFIX . '/social/google')->json('url');
        $paramsB = $this->authUrlParams($urlB);

        $this->assertSame(self::CALLBACK_URL, $paramsA['redirect_uri']);
        $this->assertSame('https://project-b.example.test/api/v1/social/google/callback', $paramsB['redirect_uri']);
    }

    public function test_incoming_host_header_cannot_change_the_callback_url(): void
    {
        foreach (['attacker.example.test', '127.0.0.1:8000', 'localhost'] as $host) {
            $url = $this->withHeaders(['Host' => $host])
                ->get(self::PREFIX . '/social/google')
                ->json('url');

            $params = $this->authUrlParams($url);

            $this->assertSame(
                self::CALLBACK_URL,
                $params['redirect_uri'],
                "Host header [{$host}] must not influence redirect_uri"
            );
            $this->assertStringNotContainsString((string) $host, $url);
        }
    }

    public function test_callback_uses_the_same_configured_callback_url(): void
    {
        // The callback passes the identical configured URI to Socialite for
        // the token exchange. The mock captures the redirectUrl() argument.
        $capturedRedirectUrl = null;

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->with(Mockery::on(function ($arg) use (&$capturedRedirectUrl) {
            $capturedRedirectUrl = $arg;

            return true;
        }))->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturnUsing(function () {
            return new \Illuminate\Http\RedirectResponse(
                'https://accounts.google.com/o/oauth2/auth?state=web'
            );
        });
        $provider->shouldReceive('user')->andReturn($this->googleUser());

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        [$state] = $this->initiate();

        $this->get(self::PREFIX . '/social/google/callback?state=' . $state)->assertRedirect();

        $this->assertSame(self::CALLBACK_URL, $capturedRedirectUrl);
    }

    // ========================================================================
    // 8–9. Frontend redirect destination & invalid provider
    // ========================================================================

    public function test_frontend_redirect_uses_the_configured_trusted_destination(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturnUsing(function () {
            return new \Illuminate\Http\RedirectResponse(
                'https://accounts.google.com/o/oauth2/auth?state=web'
            );
        });
        $provider->shouldReceive('user')->andReturn($this->googleUser());

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        [$state] = $this->initiate();

        $response = $this->get(self::PREFIX . '/social/google/callback?state=' . $state);

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::FRONTEND_URL . '/?code=', $location);

        // The one-time code is the ONLY query parameter appended.
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $this->assertArrayHasKey('code', $query);
        $this->assertCount(1, $query);
        $this->assertDatabaseHas('social_login_codes', ['code' => $query['code'], 'used' => false]);
    }

    public function test_invalid_provider_handling_remains_correct(): void
    {
        $this->get(self::PREFIX . '/social/hacking')
            ->assertStatus(500)
            ->assertJsonPath('success', false);

        $this->get(self::PREFIX . '/social/hacking/callback')->assertStatus(500);
    }

    public function test_google_frontend_redirect_override_is_used_when_configured(): void
    {
        config(['shop.social_login.google_frontend_url' => 'https://google-frontend.example.test']);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturnUsing(function () {
            return new \Illuminate\Http\RedirectResponse(
                'https://accounts.google.com/o/oauth2/auth?state=web'
            );
        });
        $provider->shouldReceive('user')->andReturn($this->googleUser());

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        [$state] = $this->initiate();

        $response = $this->get(self::PREFIX . '/social/google/callback?state=' . $state);

        $response->assertRedirect();
        $this->assertStringStartsWith(
            'https://google-frontend.example.test/?code=',
            $response->headers->get('Location')
        );
    }

    public function test_google_frontend_redirect_falls_back_to_generic_frontend_url(): void
    {
        // Explicitly unset override → the pre-existing generic behavior.
        config(['shop.social_login.google_frontend_url' => null]);

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturnUsing(function () {
            return new \Illuminate\Http\RedirectResponse(
                'https://accounts.google.com/o/oauth2/auth?state=web'
            );
        });
        $provider->shouldReceive('user')->andReturn($this->googleUser());

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        [$state] = $this->initiate();

        $response = $this->get(self::PREFIX . '/social/google/callback?state=' . $state);

        $response->assertRedirect();
        $this->assertStringStartsWith(
            self::FRONTEND_URL . '/?code=',
            $response->headers->get('Location')
        );
    }

    public function test_google_frontend_override_does_not_leak_into_other_providers(): void
    {
        // A Google-specific override must not redirect the Facebook flow.
        config([
            'shop.social_login.google_frontend_url' => 'https://google-frontend.example.test',
            'services.facebook.client_id' => 'fb-client-id',
            'services.facebook.client_secret' => 'fb-client-secret',
            'services.facebook.redirect' => 'https://oauth.example.test/api/v1/social/facebook/callback',
        ]);

        $provider = Mockery::mock(\Laravel\Socialite\Two\FacebookProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturnUsing(function () {
            return new \Illuminate\Http\RedirectResponse('https://www.facebook.com/dialog/oauth?client_id=stub');
        });
        // The Facebook callback failure path renders the frontend error
        // redirect from the generic (non-Google) frontend URL.
        $provider->shouldReceive('user')->andThrow(new \Exception('no_code'));

        Socialite::shouldReceive('driver')->with('facebook')->andReturn($provider);

        $response = $this->get(self::PREFIX . '/social/facebook/callback');

        $response->assertRedirect();
        $this->assertStringStartsWith(
            self::FRONTEND_URL . '/auth?error=social_login_failed',
            $response->headers->get('Location')
        );
        $this->assertStringNotContainsString('google-frontend.example.test', (string) $response->headers->get('Location'));
    }

    // ========================================================================
    // 10. Existing exchange contract compatibility
    // ========================================================================

    public function test_existing_exchange_contract_remains_compatible(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Exchange User',
            'email' => 'exchange@example.test',
            'password' => Hash::make('secret123'),
            'type' => 'user',
            'email_verified_at' => now(),
        ]);

        $code = bin2hex(random_bytes(32));
        SocialLoginCode::create([
            'user_id' => $user->id,
            'code' => $code,
            'expires_at' => now()->addMinutes(5),
            'used' => false,
        ]);

        $response = $this->postJson(self::PREFIX . '/social/exchange', ['code' => $code]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['success', 'message', 'token', 'token_type', 'user']);
        $this->assertSame('Bearer', $response->json('token_type'));
        $this->assertNotEmpty($response->json('token'));

        // Reusable within its TTL: the code row is preserved, not deleted.
        $this->assertDatabaseHas('social_login_codes', ['code' => $code]);

        // A second exchange with the same code succeeds with a distinct token.
        $second = $this->postJson(self::PREFIX . '/social/exchange', ['code' => $code]);

        $second->assertOk();
        $second->assertJsonPath('success', true);
        $this->assertNotSame($response->json('token'), $second->json('token'));
    }

    public function test_missing_configuration_fails_safely(): void
    {
        config(['services.google.redirect' => null]);

        $this->get(self::PREFIX . '/social/google')
            ->assertStatus(500)
            ->assertJsonPath('success', false)
            ->assertJsonPath('message', 'Social login failed, please try again.');

        $this->get(self::PREFIX . '/social/google/callback')
            ->assertRedirect(self::FRONTEND_URL . '/auth?error=social_login_failed');
    }

    public function test_misconfigured_non_string_callback_fails_safely(): void
    {
        config(['services.google.redirect' => ['not' => 'a url']]);

        $this->get(self::PREFIX . '/social/google')
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    public function test_http_callback_configuration_is_rejected_in_production(): void
    {
        config([
            'services.google.redirect' => 'http://oauth.example.test/api/v1/social/google/callback',
            'app.env' => 'production',
        ]);

        $this->get(self::PREFIX . '/social/google')
            ->assertStatus(500)
            ->assertJsonPath('success', false);
    }

    public function test_http_callback_configuration_is_rejected_on_callback_path_in_production(): void
    {
        // On the callback path the throw happens inside the try and is
        // converted into the existing failure redirect (fail-safe, not 500).
        config([
            'services.google.redirect' => 'http://oauth.example.test/api/v1/social/google/callback',
            'app.env' => 'production',
        ]);

        $this->get(self::PREFIX . '/social/google/callback')
            ->assertRedirect(self::FRONTEND_URL . '/auth?error=social_login_failed');
    }

    public function test_facebook_uses_its_own_configured_callback_url(): void
    {
        config([
            'services.facebook.client_id' => 'fb-client-id',
            'services.facebook.client_secret' => 'fb-client-secret',
            'services.facebook.redirect' => 'https://oauth.example.test/api/v1/social/facebook/callback',
        ]);

        // Capture the redirectUrl() argument the controller passes to the
        // provider — that IS the redirect_uri sent to Facebook.
        $capturedRedirectUrl = null;

        $provider = Mockery::mock(\Laravel\Socialite\Two\FacebookProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->with(Mockery::on(function ($arg) use (&$capturedRedirectUrl) {
            $capturedRedirectUrl = $arg;

            return true;
        }))->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')->andReturnUsing(function () {
            return new \Illuminate\Http\RedirectResponse('https://www.facebook.com/dialog/oauth?client_id=stub');
        });

        Socialite::shouldReceive('driver')->with('facebook')->andReturn($provider);

        $response = $this->get(self::PREFIX . '/social/facebook');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->assertSame(
            'https://oauth.example.test/api/v1/social/facebook/callback',
            $capturedRedirectUrl
        );
        $this->assertStringNotContainsString('fb-client-secret', $response->json('url'));
    }

    // ========================================================================
    // Helpers
    // ========================================================================

    private function googleUser(): SocialiteUser
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getEmail')->andReturn('user@gmail.com');
        $socialUser->shouldReceive('getName')->andReturn('Social User');
        $socialUser->shouldReceive('getId')->andReturn('113592939182870971795');

        return $socialUser;
    }

    /**
     * Run initiation and return [state, binding] — the binding cookie is not
     * used in this narrow contract but is captured for completeness.
     */
    private function initiate(string $query = ''): array
    {
        $response = $this->get(self::PREFIX . '/social/google' . $query);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $params = $this->authUrlParams($response->json('url'));

        return [$params['state'] ?? null, null];
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
