<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class UserAuthAdminTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $admin;
    private string $adminPassword = 'AdminPass123!';

    protected function setUp(): void
    {
        parent::setUp();

        $this->createAllTestTables();

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        if (!DB::table('settings')->where('language', 'en')->exists()) {
            DB::table('settings')->insert([
                'language' => 'en',
                'options' => json_encode([
                    'app_settings' => ['trust' => true],
                    'useMustVerifyEmail' => false,
                ]),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->admin = User::create([
            'name' => 'Admin User',
            'email' => 'admin@example.com',
            'password' => Hash::make($this->adminPassword),
            'phone_number' => '01000000099',
            'type' => 'admin',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ========================================================================
    // POST /api/admin-login
    // ========================================================================

    public function test_admin_login_with_valid_credentials_returns_token(): void
    {
        $response = $this->postJson(self::PREFIX . '/admin-login', [
            'email' => $this->admin->email,
            'password' => $this->adminPassword,
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure([
            'data' => ['token', 'permissions', 'email_verified', 'role'],
        ]);
        $this->assertNotEmpty($response->json('data.token'));
    }

    public function test_admin_login_fails_for_regular_user(): void
    {
        $user = User::create([
            'name' => 'Regular', 'email' => 'regular@example.com',
            'password' => Hash::make('password123'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000098', 'email_verified_at' => now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/admin-login', [
            'email' => $user->email,
            'password' => 'password123',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
    }

    public function test_admin_login_fails_with_invalid_password(): void
    {
        $response = $this->postJson(self::PREFIX . '/admin-login', [
            'email' => $this->admin->email,
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(404);
    }

    public function test_admin_login_fails_with_inactive_admin(): void
    {
        $this->admin->update(['is_active' => false]);

        $response = $this->postJson(self::PREFIX . '/admin-login', [
            'email' => $this->admin->email,
            'password' => $this->adminPassword,
        ]);

        $response->assertStatus(404);
    }

    public function test_admin_login_fails_with_unverified_email(): void
    {
        $this->admin->update(['email_verified_at' => null]);

        $response = $this->postJson(self::PREFIX . '/admin-login', [
            'email' => $this->admin->email,
            'password' => $this->adminPassword,
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'User not verified');
    }

    public function test_admin_login_fails_without_credentials(): void
    {
        $this->postJson(self::PREFIX . '/admin-login', [])->assertStatus(422);
    }

    // ========================================================================
    // POST /api/change-password
    // ========================================================================

    public function test_user_can_change_password(): void
    {
        $user = User::create([
            'name' => 'Password User', 'email' => 'pwuser@example.com',
            'password' => Hash::make('OldPass123!'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000097',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => 'OldPass123!',
            'newPassword' => 'NewPass456!',
            'newPassword_confirmation' => 'NewPass456!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $user->refresh();
        $this->assertTrue(Hash::check('NewPass456!', $user->password));
    }

    public function test_change_password_fails_with_wrong_old_password(): void
    {
        $user = User::create([
            'name' => 'Wrong Old', 'email' => 'wrongold@example.com',
            'password' => Hash::make('RealPass123!'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000096',
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => 'WrongOldPass!',
            'newPassword' => 'NewPass456!',
            'newPassword_confirmation' => 'NewPass456!',
        ]);

        $response->assertStatus(400);
    }

    public function test_change_password_fails_without_old_password(): void
    {
        $user = User::create([
            'name' => 'No Old', 'email' => 'noold@example.com',
            'password' => Hash::make('pass'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000095',
        ]);

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/change-password', [
            'newPassword' => 'NewPass456!',
            'newPassword_confirmation' => 'NewPass456!',
        ])->assertStatus(422);
    }

    public function test_change_password_fails_with_short_new_password(): void
    {
        $user = User::create([
            'name' => 'Short New', 'email' => 'shortnew@example.com',
            'password' => Hash::make('OldPass123!'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000094',
        ]);

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => 'OldPass123!',
            'newPassword' => '12345',
            'newPassword_confirmation' => '12345',
        ])->assertStatus(422);
    }

    public function test_change_password_fails_with_same_password(): void
    {
        $user = User::create([
            'name' => 'Same Pw', 'email' => 'samepw@example.com',
            'password' => Hash::make('OldPass123!'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000093',
        ]);

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => 'OldPass123!',
            'newPassword' => 'OldPass123!',
            'newPassword_confirmation' => 'OldPass123!',
        ])->assertStatus(422);
    }

    public function test_change_password_fails_for_unauthenticated_user(): void
    {
        $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => 'any',
            'newPassword' => 'NewPass456!',
            'newPassword_confirmation' => 'NewPass456!',
        ])->assertStatus(401);
    }

    // ========================================================================
    // POST /api/update-email
    // ========================================================================

    public function test_user_can_update_email(): void
    {
        $user = User::create([
            'name' => 'Email Update', 'email' => 'emailupdate@example.com',
            'password' => Hash::make('password'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000092', 'email_verified_at' => now(),
        ]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/update-email', [
            'email' => 'newemail@example.com',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'email' => 'newemail@example.com',
        ]);
    }

    public function test_update_email_fails_with_duplicate_email(): void
    {
        $user = User::create([
            'name' => 'Orig', 'email' => 'orig@example.com',
            'password' => Hash::make('password'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000091', 'email_verified_at' => now(),
        ]);
        User::create([
            'name' => 'Existing', 'email' => 'existing@example.com',
            'password' => Hash::make('password'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000090',
        ]);

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/update-email', [
            'email' => 'existing@example.com',
        ])->assertStatus(422);
    }

    public function test_update_email_fails_without_email(): void
    {
        $user = User::create([
            'name' => 'No Email', 'email' => 'noemail@example.com',
            'password' => Hash::make('password'), 'type' => 'user', 'is_active' => true,
            'phone_number' => '01000000089',
        ]);

        Sanctum::actingAs($user);

        $this->postJson(self::PREFIX . '/update-email', [])->assertStatus(422);
    }

    public function test_update_email_fails_for_unauthenticated_user(): void
    {
        $this->postJson(self::PREFIX . '/update-email', ['email' => 'any@example.com'])
            ->assertStatus(401);
    }

    // ========================================================================
    // Social Login — POST /api/social-login-token
    // ========================================================================

    public function test_social_login_fails_with_invalid_provider(): void
    {
        $response = $this->postJson(self::PREFIX . '/social-login-token', [
            'provider' => 'invalid',
            'access_token' => 'some-token',
        ]);

        $response->assertStatus(500);
    }

    public function test_social_login_fails_without_provider(): void
    {
        $this->postJson(self::PREFIX . '/social-login-token', [
            'access_token' => 'some-token',
        ])->assertStatus(422);
    }

    public function test_social_login_fails_without_token(): void
    {
        $this->postJson(self::PREFIX . '/social-login-token', [
            'provider' => 'google',
        ])->assertStatus(422);
    }
}
