<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;
use Marvel\Database\Models\SocialLoginCode;
use Marvel\Database\Models\User;
use Mockery;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class SocialLoginFlowTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private const FRONTEND_URL = 'https://meem-market-ecommerce.vercel.app';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        config([
            'shop.social_login.frontend_url' => self::FRONTEND_URL,
            'shop.social_login.code_ttl_minutes' => 5,
        ]);
    }

    private function mockGoogleProvider(SocialiteUser $socialUser): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andReturn($socialUser);

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);
    }

    private function socialiteUser(?string $email = 'user@gmail.com', string $name = 'Social User', string $id = '113592939182870971795'): SocialiteUser
    {
        $socialUser = Mockery::mock(SocialiteUser::class);
        $socialUser->shouldReceive('getEmail')->andReturn($email);
        $socialUser->shouldReceive('getName')->andReturn($name);
        $socialUser->shouldReceive('getId')->andReturn($id);

        return $socialUser;
    }

    // ========================================================================
    // GET /api/v1/social/{provider}
    // ========================================================================

    public function test_redirect_returns_provider_url(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')
            ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(self::PREFIX . '/social/google');

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('url', 'https://accounts.google.com/o/oauth2/auth');
    }

    public function test_redirect_without_type_defaults_to_web_state(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')
            ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(self::PREFIX . '/social/redirect?provider=google')->assertOk();
    }

    public function test_redirect_with_type_mobile_passes_mobile_state(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'mobile'])->andReturnSelf();
        $provider->shouldReceive('redirect')
            ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(self::PREFIX . '/social/google?type=mobile')->assertOk();
    }

    public function test_redirect_with_invalid_type_defaults_to_web_state(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('with')->with(['state' => 'web'])->andReturnSelf();
        $provider->shouldReceive('redirect')
            ->andReturn(new RedirectResponse('https://accounts.google.com/o/oauth2/auth'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get(self::PREFIX . '/social/google?type=desktop')->assertOk();
    }

    // ========================================================================
    // GET /api/v1/social/{provider}/callback
    // ========================================================================

    public function test_callback_creates_user_and_issues_single_use_code(): void
    {
        $this->mockGoogleProvider($this->socialiteUser());

        $response = $this->get(self::PREFIX . '/social/google/callback');

        $response->assertRedirect();

        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(self::FRONTEND_URL . '/?code=', $location);

        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);
        $code = $query['code'] ?? null;
        $this->assertNotNull($code);

        $this->assertDatabaseHas('social_login_codes', ['code' => $code, 'used' => false]);
        $this->assertDatabaseHas('users', ['email' => 'user@gmail.com']);
        $this->assertDatabaseHas('providers', [
            'provider' => 'google',
            'provider_user_id' => '113592939182870971795',
        ]);

        $record = SocialLoginCode::where('code', $code)->first();
        $this->assertFalse($record->used);
        $this->assertGreaterThan(now(), $record->expires_at);
    }

    public function test_callback_links_existing_user(): void
    {
        $existing = User::create([
            'name' => 'Existing',
            'email' => 'user@gmail.com',
            'password' => Hash::make('secret123'),
            'type' => 'user',
            'email_verified_at' => now(),
        ]);

        $this->mockGoogleProvider($this->socialiteUser());

        $this->get(self::PREFIX . '/social/google/callback')->assertRedirect();

        $this->assertSame(1, User::where('email', 'user@gmail.com')->count());
        $this->assertDatabaseHas('providers', [
            'user_id' => $existing->id,
            'provider' => 'google',
        ]);
    }

    public function test_callback_repeat_login_returns_same_user(): void
    {
        $this->mockGoogleProvider($this->socialiteUser());

        $this->get(self::PREFIX . '/social/google/callback')->assertRedirect();
        $this->get(self::PREFIX . '/social/google/callback')->assertRedirect();

        $this->assertSame(1, User::where('email', 'user@gmail.com')->count());
        $this->assertSame(1, \Marvel\Database\Models\Provider::count());
    }

    public function test_callback_creates_user_without_provider_email(): void
    {
        $this->mockGoogleProvider($this->socialiteUser(email: null, name: 'PhoneOnly', id: '987654321'));

        $this->get(self::PREFIX . '/social/google/callback')->assertRedirect();

        $this->assertDatabaseHas('users', [
            'name' => 'PhoneOnly',
            'email' => null,
        ]);
        $this->assertDatabaseHas('providers', [
            'provider' => 'google',
            'provider_user_id' => '987654321',
        ]);
        $this->assertSame(1, User::whereNull('email')->count());
    }

    public function test_callback_redirects_to_frontend_error_when_provider_fails(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \Exception('access_denied'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(self::PREFIX . '/social/google/callback');

        $response->assertRedirect(self::FRONTEND_URL . '/auth?error=social_login_failed');
        $this->assertDatabaseCount('social_login_codes', 0);
    }

    public function test_callback_mobile_returns_json_code_instead_of_redirect(): void
    {
        $this->mockGoogleProvider($this->socialiteUser());

        $response = $this->get(self::PREFIX . '/social/google/callback?state=mobile');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $code = $response->json('code');
        $this->assertNotEmpty($code);

        $this->assertDatabaseHas('social_login_codes', ['code' => $code, 'used' => false]);
        $this->assertDatabaseHas('users', ['email' => 'user@gmail.com']);
    }

    public function test_callback_mobile_returns_json_error_when_provider_fails(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \Exception('access_denied'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->get(self::PREFIX . '/social/google/callback?state=mobile');

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Social login failed, please try again.');
        $this->assertDatabaseCount('social_login_codes', 0);
    }

    public function test_callback_mobile_returns_arabic_error_message(): void
    {
        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('stateless')->andReturnSelf();
        $provider->shouldReceive('redirectUrl')->andReturnSelf();
        $provider->shouldReceive('user')->andThrow(new \Exception('access_denied'));

        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $response = $this->withHeaders(['lang' => 'ar'])
            ->get(self::PREFIX . '/social/google/callback?state=mobile');

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'فشل تسجيل الدخول الاجتماعي، يرجى المحاولة مرة أخرى.');
        $this->assertDatabaseCount('social_login_codes', 0);
    }


    // ========================================================================
    // POST /api/v1/social/exchange
    // ========================================================================

    public function test_exchange_returns_token_and_deletes_code(): void
    {
        $user = User::create([
            'name' => 'Social User',
            'email' => 'user@gmail.com',
            'password' => Hash::make('secret123'),
            'type' => 'user',
            'email_verified_at' => now(),
        ]);

        $code = SocialLoginCode::create([
            'user_id' => $user->id,
            'code' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addMinutes(5),
            'used' => false,
        ]);

        $response = $this->postJson(self::PREFIX . '/social/exchange', ['code' => $code->code]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'success', 'message', 'token', 'token_type', 'user',
        ]);
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('Bearer', $response->json('token_type'));

        $this->assertDatabaseMissing('social_login_codes', ['id' => $code->id]);

        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
        ]);
    }

    public function test_exchange_rejects_replay_of_used_code(): void
    {
        $user = User::create([
            'name' => 'Social User',
            'email' => 'user@gmail.com',
            'password' => Hash::make('secret123'),
            'type' => 'user',
            'email_verified_at' => now(),
        ]);

        $code = SocialLoginCode::create([
            'user_id' => $user->id,
            'code' => bin2hex(random_bytes(32)),
            'expires_at' => now()->addMinutes(5),
            'used' => false,
        ]);

        $first = $this->postJson(self::PREFIX . '/social/exchange', ['code' => $code->code]);
        $first->assertOk();
        $first->assertJsonPath('success', true);

        $replay = $this->postJson(self::PREFIX . '/social/exchange', ['code' => $code->code]);

        $replay->assertStatus(400);
        $replay->assertJsonPath('success', false);
        $this->assertSame(1, User::where('email', 'user@gmail.com')->first()->tokens()->count());
    }

    public function test_exchange_rejects_expired_code(): void
    {
        $user = User::create([
            'name' => 'Social User',
            'email' => 'user@gmail.com',
            'password' => Hash::make('secret123'),
            'type' => 'user',
            'email_verified_at' => now(),
        ]);

        $code = SocialLoginCode::create([
            'user_id' => $user->id,
            'code' => bin2hex(random_bytes(32)),
            'expires_at' => now()->subMinute(),
            'used' => false,
        ]);

        $response = $this->postJson(self::PREFIX . '/social/exchange', ['code' => $code->code]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        $this->assertDatabaseHas('social_login_codes', ['id' => $code->id, 'used' => false]);
    }

    public function test_exchange_rejects_unknown_code(): void
    {
        $response = $this->postJson(self::PREFIX . '/social/exchange', [
            'code' => bin2hex(random_bytes(32)),
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        $response->assertJsonPath('message', 'Invalid or expired authorization code.');
    }

    public function test_exchange_requires_code_field(): void
    {
        $response = $this->postJson(self::PREFIX . '/social/exchange', []);

        $response->assertStatus(422);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
