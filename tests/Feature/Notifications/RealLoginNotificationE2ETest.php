<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Marvel\Database\Models\User;

/**
 * DEFINITIVE E2E: the full REAL logged-in-user path with NO auth stubs.
 *
 * Unlike the rest of the suite (which uses Sanctum::actingAs to bypass the
 * token pipeline), this test performs a REAL login through POST /api/v1/token,
 * uses the REAL returned Bearer token against /api/v1/me and the notification
 * REST API, dispatches the REAL OrderCreated event, and exercises the REAL
 * channel-authorization HTTP endpoints (/broadcasting/auth and
 * /api/v1/broadcasting/auth) with that exact token.
 *
 * The only swapped component remains the Pusher HTTP client (RecordingPusher)
 * so no external network calls are made.
 */
class RealLoginNotificationE2ETest extends NotificationE2ETestCase
{
    protected function createUser(string $type = 'user', array $attributes = []): User
    {
        $user = User::create(array_merge([
            'name' => ucfirst($type) . ' User',
            'email' => $type . '-' . Str::random(6) . '@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => $type,
            'phone_number' => ($type === 'admin' ? '02' : '01') . rand(100000000, 999999999),
        ], $attributes));

        $user->refresh();

        return $user;
    }

    private function login(string $email, string $password = 'password'): string
    {
        $response = $this->postJson(self::API_PREFIX . '/token', [
            'email' => $email,
            'password' => $password,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $token = $response->json('data.token');
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        return $token;
    }

    public function test_real_login_returns_token_and_me_resolves_user(): void
    {
        $user = $this->createUser('user');

        $token = $this->login($user->email);

        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $user->id);
        $response->assertJsonPath('data.email', $user->email);
    }

    public function test_real_login_invalid_credentials_rejected(): void
    {
        $user = $this->createUser('user');

        $this->postJson(self::API_PREFIX . '/token', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ])->assertStatus(404);
    }

    public function test_real_login_notifications_api_with_real_token(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $token = $this->login($user->email);

        $response = $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('data.meta.total', 1);

        $types = collect($response->json('data.data'))->pluck('type')->all();
        $this->assertContains('order.created', $types);
    }

    /**
     * Full stack: real login -> real trigger -> DB row -> REST API ->
     * broadcast recorded to private-users.{id}.
     */
    public function test_full_real_login_pipeline(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        $token = $this->login($user->email);

        event(new OrderCreated($order));

        // DB row
        $this->assertDatabaseNotification($user, 'order.created', function ($n) use ($order) {
            $this->assertEquals('order.created', $n->type);
            $this->assertEquals($order->id, $n->data['resource_id']);
        });

        // REST API exposes it to the real token holder
        $this->withToken($token)
            ->getJson(self::API_PREFIX . '/notifications')
            ->assertStatus(200)
            ->assertJsonPath('data.meta.total', 1);

        // Broadcast payload for the owning user
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals('order.created', $broadcast['data']['type']);
    }

    /**
     * Channel authorization over the real HTTP endpoint for API clients:
     * POST /api/v1/broadcasting/auth with a REAL bearer token must grant
     * access to the user's own private-users.{id} channel.
     */
    public function test_api_broadcasting_auth_grants_own_channel_with_real_token(): void
    {
        $user = $this->createUser('user');
        $token = $this->login($user->email);

        $response = $this->withToken($token)
            ->postJson(self::API_PREFIX . '/broadcasting/auth', [
                'channel_name' => 'private-users.' . $user->id,
                'socket_id' => '1234.5678',
            ]);

        $response->assertStatus(200);
        $response->assertJsonStructure(['auth']);
    }

    /**
     * Channel authorization must deny another user's channel even with a real
     * valid token for the caller.
     */
    public function test_api_broadcasting_auth_denies_other_user_channel(): void
    {
        $owner = $this->createUser('user');
        $caller = $this->createUser('user');
        $token = $this->login($caller->email);

        $this->withToken($token)
            ->postJson(self::API_PREFIX . '/broadcasting/auth', [
                'channel_name' => 'private-users.' . $owner->id,
                'socket_id' => '1234.5678',
            ])
            ->assertStatus(403);
    }

    public function test_api_broadcasting_auth_rejects_missing_token(): void
    {
        $user = $this->createUser('user');

        $this->postJson(self::API_PREFIX . '/broadcasting/auth', [
            'channel_name' => 'private-users.' . $user->id,
            'socket_id' => '1234.5678',
        ])->assertStatus(401);
    }

    /**
     * The legacy default endpoint POST /broadcasting/auth (web middleware) is
     * exercised with a REAL bearer token. A Bearer token should NOT be
     * required for this to work either if the guard resolves it; we assert the
     * observed behaviour so the frontend contract is explicit. Browser CORS is
     * separately proven by the /api/v1 path being the only CORS-covered one.
     */
    public function test_legacy_broadcasting_auth_observes_behavior_with_real_token(): void
    {
        $user = $this->createUser('user');
        $token = $this->login($user->email);

        $this->withToken($token)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-users.' . $user->id,
                'socket_id' => '1234.5678',
            ])
            ->assertStatus(200)
            ->assertJsonStructure(['auth']);
    }

    /**
     * Both broadcast auth endpoints carry CORS headers because the Marvel
     * cors config overrides the root config with '*' in paths, so a browser
     * on the Vercel frontend can reach either endpoint cross-origin.
     */
    public function test_cors_headers_on_both_broadcasting_auth_paths(): void
    {
        $user = $this->createUser('user');
        $token = $this->login($user->email);

        $apiPath = $this->withToken($token)
            ->postJson(self::API_PREFIX . '/broadcasting/auth', [
                'channel_name' => 'private-users.' . $user->id,
                'socket_id' => '1234.5678',
            ]);

        $apiPath->assertStatus(200);
        $this->assertNotEmpty(
            $apiPath->headers->get('Access-Control-Allow-Origin') ?? '',
            'Expected CORS header on /api/v1/broadcasting/auth.'
        );

        $legacyPath = $this->withToken($token)
            ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class)
            ->postJson('/broadcasting/auth', [
                'channel_name' => 'private-users.' . $user->id,
                'socket_id' => '1234.5678',
            ]);

        $legacyPath->assertStatus(200);
        $this->assertNotEmpty(
            $legacyPath->headers->get('Access-Control-Allow-Origin') ?? '',
            'Expected CORS header on /broadcasting/auth (Marvel cors paths include \'*\').'
        );
    }
}