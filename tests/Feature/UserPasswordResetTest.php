<?php

declare(strict_types=1);

namespace Tests\Feature;

use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class UserPasswordResetTest extends TestCase
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

        $this->user = User::create([
            'name' => 'Password Reset User',
            'email' => 'reset@example.com',
            'password' => Hash::make($this->plainPassword),
            'phone_number' => '01000000090',
            'type' => 'user',
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }

    private function insertResetToken(): void
    {
        DB::table('password_resets')->where('email', $this->user->email)->delete();
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make('test-token-789'),
            'created_at' => Carbon::now(),
        ]);
    }

    // ========================================================================
    // POST /api/forget-password
    // ========================================================================

    public function test_forget_password_sends_reset_token(): void
    {
        $response = $this->postJson(self::PREFIX . '/forget-password', [
            'email' => $this->user->email,
        ]);

        $response->assertOk();
        $this->assertDatabaseHas('password_resets', [
            'email' => $this->user->email,
        ]);
    }

    public function test_forget_password_does_not_leak_email_existence(): void
    {
        $response = $this->postJson(self::PREFIX . '/forget-password', [
            'email' => 'nonexistent@example.com',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_forget_password_updates_existing_token(): void
    {
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make('old-token'),
            'created_at' => Carbon::now()->subHour(),
        ]);

        $this->postJson(self::PREFIX . '/forget-password', [
            'email' => $this->user->email,
        ]);

        $tokenData = DB::table('password_resets')
            ->where('email', $this->user->email)
            ->first();

        $this->assertNotNull($tokenData);
        $this->assertFalse(Hash::check('old-token', $tokenData->token));
    }

    // ========================================================================
    // POST /api/verify-forget-password-token
    // ========================================================================

    public function test_verify_forget_password_token_succeeds_with_valid_token(): void
    {
        $token = 'valid-token-123';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/verify-forget-password-token', [
            'email' => $this->user->email,
            'otp' => $token,
        ]);

        $response->assertOk();
    }

    public function test_verify_forget_password_token_fails_with_expired_token(): void
    {
        $expireMinutes = (int) config('auth.passengers.users.expire', 60);
        $token = 'expired-token';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now()->subMinutes($expireMinutes + 1),
        ]);

        $response = $this->postJson(self::PREFIX . '/verify-forget-password-token', [
            'email' => $this->user->email,
            'otp' => $token,
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    public function test_verify_forget_password_token_fails_with_invalid_token(): void
    {
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make('real-token'),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/verify-forget-password-token', [
            'email' => $this->user->email,
            'otp' => 'wrong-token',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    // ========================================================================
    // POST /api/reset-password
    // ========================================================================

    public function test_reset_password_succeeds_with_valid_token(): void
    {
        $token = 'reset-token-456';
        DB::table('password_resets')->insert([
            'email' => $this->user->email,
            'token' => Hash::make($token),
            'created_at' => Carbon::now(),
        ]);

        $response = $this->postJson(self::PREFIX . '/reset-password', [
            'email' => $this->user->email,
            'otp' => $token,
            'password' => 'NewPassword789!',
            'password_confirmation' => 'NewPassword789!',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $this->user->refresh();
        $this->assertTrue(Hash::check('NewPassword789!', $this->user->password));

        $this->assertDatabaseMissing('password_resets', [
            'email' => $this->user->email,
        ]);
    }

    public function test_reset_password_fails_with_wrong_token(): void
    {
        $response = $this->postJson(self::PREFIX . '/reset-password', [
            'email' => $this->user->email,
            'otp' => 'wrong-otp',
            'password' => 'NewPassword789!',
            'password_confirmation' => 'NewPassword789!',
        ]);

        $response->assertStatus(400);
    }

    public function test_reset_password_fails_with_mismatched_confirmation(): void
    {
        $this->insertResetToken();
        $this->postJson(self::PREFIX . '/reset-password', [
            'email' => $this->user->email,
            'otp' => 'test-token-789',
            'password' => 'NewPassword789!',
            'password_confirmation' => 'DifferentPassword!',
        ])->assertStatus(422);
    }

    public function test_reset_password_fails_with_short_password(): void
    {
        $this->insertResetToken();
        $this->postJson(self::PREFIX . '/reset-password', [
            'email' => $this->user->email,
            'otp' => 'test-token-789',
            'password' => '1234567',
            'password_confirmation' => '1234567',
        ])->assertStatus(422);
    }

    public function test_reset_password_fails_without_email(): void
    {
        $this->postJson(self::PREFIX . '/reset-password', [
            'otp' => 'test-token-789',
            'password' => 'NewPassword789!',
            'password_confirmation' => 'NewPassword789!',
        ])->assertStatus(422);
    }

    // ========================================================================
    // POST /api/contact-us
    // ========================================================================

    public function test_contact_us_sends_email(): void
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson(self::PREFIX . '/contact-us', [
            'subject' => 'Test Subject',
            'name' => 'Test User',
            'email' => $this->user->email,
            'message' => 'This is a test message.',
        ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
    }

    public function test_contact_us_fails_without_subject(): void
    {
        Sanctum::actingAs($this->user);

        $this->postJson(self::PREFIX . '/contact-us', [
            'name' => 'Test',
            'email' => $this->user->email,
            'message' => 'Message',
        ])->assertStatus(422);
    }
}
