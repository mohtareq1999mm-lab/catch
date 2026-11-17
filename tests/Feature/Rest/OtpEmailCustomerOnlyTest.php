<?php

declare(strict_types=1);

namespace Tests\Feature\Rest;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Marvel\Database\Models\User;
use Spatie\OneTimePasswords\Models\OneTimePassword;
use Spatie\OneTimePasswords\Support\OriginInspector\DoNotEnforceOrigin;
use Spatie\OneTimePasswords\Support\OriginInspector\OriginEnforcer;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class OtpEmailCustomerOnlyTest extends TestCase
{
    use DatabaseTransactions, CreatesTestTables;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('en');

        $this->createAllTestTables();

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
    }

    private function createUser(string $type, bool $isActive, string $phoneNumber): User
    {
        return User::create([
            'name' => ucfirst($type) . ' OTP User',
            'email' => $type . '.otp.' . ($isActive ? 'active' : 'inactive') . '@example.com',
            'password' => Hash::make('Password123!'),
            'phone_number' => $phoneNumber,
            'type' => $type,
            'is_active' => $isActive,
            'email_verified_at' => now(),
        ]);
    }

    private function issueOtp(User $user): void
    {
        OneTimePassword::create([
            'password' => '123456',
            'origin_properties' => [],
            'expires_at' => now()->addMinutes(2),
            'authenticatable_id' => $user->id,
            'authenticatable_type' => User::class,
        ]);
    }

    public function test_email_otp_rejects_active_admin_without_token(): void
    {
        $admin = $this->createUser('admin', true, '01000000201');
        $this->issueOtp($admin);
        $baseline = DB::table('personal_access_tokens')->count();

        $response = $this->postJson('/api/v1/otp-login', [
            'email' => $admin->email,
            'code' => '123456',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertSame($baseline, DB::table('personal_access_tokens')->count());
    }

    public function test_email_otp_rejects_other_non_customer_without_token(): void
    {
        $vendor = $this->createUser('vendor', true, '01000000202');
        $this->issueOtp($vendor);
        $baseline = DB::table('personal_access_tokens')->count();

        $response = $this->postJson('/api/v1/otp-login', [
            'email' => $vendor->email,
            'code' => '123456',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertSame($baseline, DB::table('personal_access_tokens')->count());
    }

    public function test_email_otp_rejects_inactive_customer_without_token(): void
    {
        $inactive = $this->createUser('user', false, '01000000203');
        $this->issueOtp($inactive);
        $baseline = DB::table('personal_access_tokens')->count();

        $response = $this->postJson('/api/v1/otp-login', [
            'email' => $inactive->email,
            'code' => '123456',
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('success', false);
        $this->assertSame($baseline, DB::table('personal_access_tokens')->count());
    }

    public function test_email_otp_accepts_active_customer(): void
    {
        $customer = $this->createUser('user', true, '01000000204');
        $this->issueOtp($customer);

        $response = $this->postJson('/api/v1/otp-login', [
            'email' => $customer->email,
            'code' => '123456',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertNotEmpty($response->json('data.token'));
    }
}
