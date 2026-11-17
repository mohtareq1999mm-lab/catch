<?php

declare(strict_types=1);

namespace Tests\Feature\Rest;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;
use Spatie\OneTimePasswords\Support\OriginInspector\DoNotEnforceOrigin;
use Spatie\OneTimePasswords\Support\OriginInspector\OriginEnforcer;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

/**
 * #74: master-bypass coverage for the EMAIL login path over the real
 * HTTP endpoint (POST /api/v1/otp-login → verifyLoginOtp).
 *
 * The universal static-bypass check (Marvel\Otp\StaticBypass, ADR 0003)
 * accepts the master value in non-production, rejects everything else,
 * and leaves exactly one audit line per acceptance — the actor
 * identifier only, never the accepted code.
 */
class OtpEmailMasterBypassTest extends TestCase
{
    use CreatesTestTables;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        // Schema work must happen OUTSIDE the per-test transaction: DDL
        // implicitly commits in MySQL, so a mid-transaction DDL would leak
        // this test's rows into the shared verification database. The
        // per-test transaction is started here instead — after the DDL —
        // and rolled back in tearDown.
        $this->createAllTestTables();

        // The reject path falls through to the Spatie OTP verification
        // (same defensive setup as PostAuthenticationServiceTest: the
        // HTTP-test requests carry no signed origin).
        if (!Schema::hasTable('one_time_passwords')) {
            Schema::create('one_time_passwords', function (Blueprint $table) {
                $table->id();
                $table->string('password');
                $table->text('origin_properties')->nullable();
                $table->dateTime('expires_at');
                $table->morphs('authenticatable');
                $table->timestamps();
            });
        }
        $this->app->bind(OriginEnforcer::class, DoNotEnforceOrigin::class);

        // The bypass enabled, with a master value distinct from the local
        // code so the tests prove which value the check accepts.
        config([
            'auth.local_otp_enabled' => true,
            'auth.local_otp_code' => '123456',
            'auth.master_otp' => '999888',
        ]);

        DB::beginTransaction();
    }

    protected function tearDown(): void
    {
        DB::rollBack();

        parent::tearDown();
    }

    private function activeCustomer(): User
    {
        // Factory defaults give an active customer; a unique email per test
        // keeps the per-account lockout counters isolated between tests.
        return User::factory()->create([
            'email' => 'bypass-' . uniqid('', true) . '@example.test',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function email_otp_login_accepts_the_master_code_without_a_one_time_password()
    {
        $user = $this->activeCustomer();
        Log::spy();

        $response = $this->postJson('/api/v1/otp-login', [
            'email' => $user->email,
            'code' => '999888',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
        $this->assertNotNull($user->fresh()->email_verified_at);

        // Exactly one audit line per acceptance: actor identifier only —
        // the accepted code and any credential never reach the log.
        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $message, array $context) use ($user) {
            if (($context['actor'] ?? null) !== $user->email) {
                return false;
            }

            // Negative assertion: no accepted code, no local code, and no
            // credential value anywhere in the logged entry.
            $serialized = json_encode([$message, $context]);
            foreach (['999888', '123456'] as $forbidden) {
                if (str_contains($serialized, $forbidden)) {
                    return false;
                }
            }

            return true;
        });
    }

    /** @test */
    public function email_otp_login_rejects_a_wrong_code_without_an_audit_line()
    {
        $user = $this->activeCustomer();
        Log::spy();

        $response = $this->postJson('/api/v1/otp-login', [
            'email' => $user->email,
            'code' => '000000',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);

        // Rejections never audit.
        Log::shouldNotHaveReceived('warning');
    }

    /** @test */
    public function email_otp_login_rejects_the_master_code_when_the_local_path_is_disabled()
    {
        // ADR 0003: the Local OTP switch is the ONLY gate — a configured
        // master value is a value override, never its own enable switch.
        config(['auth.local_otp_enabled' => false]);

        $user = $this->activeCustomer();
        Log::spy();

        $response = $this->postJson('/api/v1/otp-login', [
            'email' => $user->email,
            'code' => '999888',
        ]);

        $response->assertStatus(400);
        $response->assertJsonPath('success', false);
        Log::shouldNotHaveReceived('warning');
    }
}
