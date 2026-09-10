<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $user;
    private string $plainPassword = 'Password123!';

    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('en');

        $this->createAllTestTables();

        $this->user = User::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => Hash::make($this->plainPassword),
            'phone_number' => '01000000001',
            'type' => 'user',
            'is_active' => true,
        ]);
    }

    // =========================================================================
    // POST /token — Login
    // =========================================================================

    public function test_login_with_valid_credentials_returns_token()
    {
        $response = $this->postJson(self::PREFIX . '/token', [
            'email' => $this->user->email,
            'password' => $this->plainPassword,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'message',
            'status',
            'data' => ['token', 'email_verified'],
        ]);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_with_invalid_password_returns_404()
    {
        $response = $this->postJson(self::PREFIX . '/token', [
            'email' => $this->user->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_login_with_nonexistent_email_returns_404()
    {
        $response = $this->postJson(self::PREFIX . '/token', [
            'email' => 'nonexistent@example.com',
            'password' => $this->plainPassword,
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_login_with_inactive_user_returns_404()
    {
        $this->user->update(['is_active' => false]);

        $response = $this->postJson(self::PREFIX . '/token', [
            'email' => $this->user->email,
            'password' => $this->plainPassword,
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_login_with_phone_number_works()
    {
        $response = $this->postJson(self::PREFIX . '/token', [
            'phone_number' => $this->user->phone_number,
            'password' => $this->plainPassword,
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_login_without_credentials_returns_422()
    {
        $response = $this->postJson(self::PREFIX . '/token', []);

        $response->assertStatus(422);
    }

    // =========================================================================
    // POST /logout — Logout
    // =========================================================================

    public function test_logout_revokes_token()
    {
        $token = $this->user->createToken('auth_token')->plainTextToken;

        $response = $this->withToken($token)->postJson(self::PREFIX . '/logout');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_without_token_returns_401()
    {
        $response = $this->postJson(self::PREFIX . '/logout');

        $response->assertStatus(401);
    }

    public function test_token_is_invalid_after_logout()
    {
        $token = $this->user->createToken('auth_token')->plainTextToken;

        $this->withToken($token)->postJson(self::PREFIX . '/logout');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    // =========================================================================
    // GET /me — Current User Profile
    // =========================================================================

    public function test_me_returns_authenticated_user()
    {
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->getJson(self::PREFIX . '/me');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.email', $this->user->email);
    }

    public function test_me_without_auth_returns_401()
    {
        $response = $this->getJson(self::PREFIX . '/me');

        $response->assertStatus(401);
    }

    public function test_me_with_invalid_token_returns_401()
    {
        $response = $this->withToken('invalid-token')->getJson(self::PREFIX . '/me');

        $response->assertStatus(401);
    }

    // =========================================================================
    // POST /register — Registration
    // =========================================================================

    public function test_register_creates_user()
    {
        $response = $this->postJson(self::PREFIX . '/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'newuser@gmail.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '01000000002',
            'policy' => '1',
        ]);

        $this->assertContains($response->status(), [200, 201]);
        $response->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'email' => 'newuser@gmail.com',
            'type' => 'user',
            'is_active' => true,
        ]);
    }

    public function test_register_allows_optional_email()
    {
        $response = $this->postJson(self::PREFIX . '/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '01000000003',
            'policy' => '1',
        ]);

        $this->assertContains($response->status(), [200, 201]);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('users', [
            'phone_number' => '01000000003',
            'email' => null,
        ]);
    }

    public function test_register_requires_password_confirmation()
    {
        $response = $this->postJson(self::PREFIX . '/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'newuser@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'wrong-confirmation',
            'phone_number' => '01000000004',
            'policy' => '1',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_duplicate_email_returns_422()
    {
        $response = $this->postJson(self::PREFIX . '/register', [
            'first_name' => 'Duplicate',
            'last_name' => 'User',
            'email' => $this->user->email,
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '01000000005',
            'policy' => '1',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_creates_user_with_type_user()
    {
        $response = $this->postJson(self::PREFIX . '/register', [
            'first_name' => 'Inactive',
            'last_name' => 'User',
            'email' => 'inactive@gmail.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '01000000006',
            'policy' => '1',
        ]);

        $this->assertContains($response->status(), [200, 201]);

        $user = User::where('email', 'inactive@gmail.com')->first();
        $this->assertNotNull($user);
        $this->assertEquals('user', $user->type);
    }

    // =========================================================================
    // Token lifetime / validation
    // =========================================================================

    public function test_token_can_access_protected_endpoints()
    {
        Sanctum::actingAs($this->user, ['*']);

        $response = $this->getJson(self::PREFIX . '/me');

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $this->user->id);
    }

    public function test_different_users_get_different_tokens()
    {
        $user2 = User::create([
            'name' => 'User Two',
            'email' => 'user2@example.com',
            'password' => Hash::make('Password123!'),
            'type' => 'user',
            'is_active' => true,
        ]);

        $token1 = $this->user->createToken('auth_token')->plainTextToken;
        $token2 = $user2->createToken('auth_token')->plainTextToken;

        $this->assertNotEquals($token1, $token2);

        $this->assertDatabaseCount('personal_access_tokens', 2);
    }
}
