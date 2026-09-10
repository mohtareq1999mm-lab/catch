<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Spatie\Permission\Models\Role;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class UserAuthRegressionTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private const PREFIX = '/api/v1';

    private User $user;
    private string $plainPassword = 'Password123!';

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

        if (!Role::where('name', 'customer')->exists()) {
            Role::create(['name' => 'customer', 'guard_name' => 'api']);
        }

        $this->user = User::create([
            'name' => 'Regression Test User',
            'email' => 'regression@example.com',
            'password' => Hash::make($this->plainPassword),
            'phone_number' => '01000000099',
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    // ========================================================================
    // REGRESSION: Password change must revoke all existing tokens
    // ========================================================================

    public function test_password_change_revokes_existing_tokens(): void
    {
        Sanctum::actingAs($this->user);

        $this->user->createToken('test-token');
        $this->assertEquals(1, $this->user->tokens()->count());

        $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => $this->plainPassword,
            'newPassword' => 'NewStrongPass1!',
            'newPassword_confirmation' => 'NewStrongPass1!',
        ])->assertOk();

        $this->assertEquals(0, $this->user->tokens()->count(), 'All tokens should be revoked after password change');
    }

    public function test_old_token_revoked_after_password_change(): void
    {
        Sanctum::actingAs($this->user);
        $token = $this->user->createToken('old-token')->plainTextToken;
        $this->assertEquals(1, $this->user->tokens()->count());

        $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => $this->plainPassword,
            'newPassword' => 'NewStrongPass1!',
            'newPassword_confirmation' => 'NewStrongPass1!',
        ])->assertOk();

        $this->assertEquals(0, $this->user->tokens()->count(),
            'All tokens must be revoked after password change');
    }

    // ========================================================================
    // REGRESSION: No hardcoded OTP bypass '123456'
    // ========================================================================

    public function test_verify_forget_password_token_rejects_static_otp_code(): void
    {
        $response = $this->postJson(self::PREFIX . '/verify-forget-password-token', [
            'email' => $this->user->email,
            'otp' => '123456',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    public function test_reset_password_rejects_static_otp(): void
    {
        $response = $this->postJson(self::PREFIX . '/reset-password', [
            'email' => $this->user->email,
            'otp' => '123456',
            'password' => 'NewPassword789!',
            'password_confirmation' => 'NewPassword789!',
        ]);

        $response->assertStatus(400);
    }

    // ========================================================================
    // REGRESSION: Default role assigned on registration
    // ========================================================================

    public function test_registration_assigns_default_customer_role(): void
    {
        $response = $this->postJson(self::PREFIX . '/register', [
            'first_name' => 'New',
            'last_name' => 'User',
            'email' => 'newuser@gmail.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'phone_number' => '01000000088',
            'policy' => '1',
        ]);

        $response->assertSuccessful();
        $response->assertJsonPath('success', true);

        $newUser = User::where('email', 'newuser@gmail.com')->first();
        if (!$newUser) {
            $newUser = User::orderBy('id', 'desc')->first();
            $this->assertNotEquals($this->user->id, $newUser?->id);
        }
        $this->assertNotNull($newUser, 'Newly registered user should exist in database');
        $this->assertTrue($newUser->hasRole('customer'), 'Newly registered user should have customer role');
    }

    // ========================================================================
    // REGRESSION: Reset password wrapped in transaction
    // ========================================================================

    public function test_reset_password_rejects_invalid_token_inside_transaction(): void
    {
        $response = $this->postJson(self::PREFIX . '/reset-password', [
            'email' => $this->user->email,
            'otp' => 'invalid-token',
            'password' => 'NewPassword789!',
            'password_confirmation' => 'NewPassword789!',
        ]);

        $response->assertStatus(400);

        $this->user->refresh();
        $this->assertTrue(Hash::check($this->plainPassword, $this->user->password));
    }

    public function test_reset_password_cleans_up_token_on_success(): void
    {
        $tokenValue = 'valid-reset-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($tokenValue),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/reset-password', [
            'email' => $this->user->email,
            'otp' => $tokenValue,
            'password' => 'ResetPass789!',
            'password_confirmation' => 'ResetPass789!',
        ]);

        $response->assertOk();

        $this->assertDatabaseMissing('password_resets', [
            'email' => $this->user->email,
        ]);
    }

    // ========================================================================
    // REGRESSION: Password change validation
    // ========================================================================

    public function test_change_password_rejects_wrong_old_password(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson(self::PREFIX . '/change-password', [
            'oldPassword' => 'WrongOldPass1!',
            'newPassword' => 'NewStrongPass1!',
            'newPassword_confirmation' => 'NewStrongPass1!',
        ])->assertStatus(400);
    }
}
