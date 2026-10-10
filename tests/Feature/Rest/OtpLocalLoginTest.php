<?php

declare(strict_types=1);

namespace Tests\Feature\Rest;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * Local OTP login — the LocalGateway fallback (static id
 * 'local-verification-id') must accept the configured static code so
 * phone login works without a real SMS provider, and must keep
 * rejecting anything else.
 */
class OtpLocalLoginTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

        // Deterministic gateway: no external credentials involved.
        config(['auth.active_otp_gateway' => 'local']);
        config(['auth.local_otp_enabled' => true, 'auth.local_otp_code' => '123456']);

        $this->user = User::factory()->withoutEmail()->create([
            'phone_number' => '+2011185151',
            'password' => bcrypt('password'),
        ]);
        // Factory may randomize activation; otpLogin requires an active user.
        $this->user->update(['is_active' => true]);
    }

    /** @test */
    public function otp_login_succeeds_with_the_static_local_code()
    {
        $response = $this->postJson('/api/v1/otp-login', [
            'phone_number' => '+2011185151',
            'otp_id' => 'local-verification-id',
            'code' => '123456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
    }

    /** @test */
    public function otp_login_fails_with_a_wrong_code()
    {
        $response = $this->postJson('/api/v1/otp-login', [
            'phone_number' => '+2011185151',
            'otp_id' => 'local-verification-id',
            'code' => '000000',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function otp_login_fails_when_the_local_path_is_disabled()
    {
        config(['auth.local_otp_enabled' => false]);

        $response = $this->postJson('/api/v1/otp-login', [
            'phone_number' => '+2011185151',
            'otp_id' => 'local-verification-id',
            'code' => '123456',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
    }

    /** @test */
    public function otp_login_fails_for_an_unknown_phone_number()
    {
        $response = $this->postJson('/api/v1/otp-login', [
            'phone_number' => '+20999999999',
            'otp_id' => 'local-verification-id',
            'code' => '123456',
        ]);

        $response->assertStatus(404);
    }
}
