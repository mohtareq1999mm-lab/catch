<?php

namespace Tests\Feature\Phase0;

use App\Jobs\SendFcmNotificationJob;
use App\Models\DeviceToken;
use App\Services\Firebase\FcmService;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

/**
 * #16 regression: FCM pushes must target ONLY the intended notifiable's
 * device tokens. Pre-fix behavior broadcast every notification to the whole
 * device_tokens table (cross-user data leak + cost).
 */
class FcmScopingTest extends TestCase
{
    private array $userAIds = [];
    private array $userBIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! \Schema::hasTable('users')) {
            \Schema::create('users', function ($t) {
                $t->id();
                $t->string('name')->nullable();
                $t->string('email')->nullable();
                $t->timestamps();
            });
        }

        if (! \Schema::hasTable('device_tokens')) {
            \Schema::create('device_tokens', function ($t) {
                $t->id();
                $t->uuid('uuid')->unique();
                $t->foreignId('user_id')->nullable();
                $t->string('token', 512)->unique();
                $t->string('client', 32);
                $t->string('platform', 16)->default('android');
                $t->timestamp('last_used_at')->nullable();
                $t->timestamps();
            });
        }

        foreach (['A' => 'a@test.local', 'B' => 'b@test.local'] as $key => $email) {
            $id = (int) \DB::table('users')->insertGetId([
                'name' => "User {$key}", 'email' => $email,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->{"user{$key}Ids"} = [$id];
        }
    }

    private function seedToken(int $userId, string $token, string $client = 'client_a'): void
    {
        DeviceToken::query()->create([
            'uuid' => \Illuminate\Support\Str::uuid()->toString(),
            'user_id' => $userId,
            'token' => $token,
            'client' => $client,
            'platform' => 'android',
        ]);
    }

    /** @test */
    public function job_targets_only_the_intended_users_tokens()
    {
        $this->seedToken($this->userAIds[0], 'token-A1');
        $this->seedToken($this->userAIds[0], 'token-A2', 'client_b');
        $this->seedToken($this->userBIds[0], 'token-B1');

        $sent = [];
        $fcm = Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToClient')
            ->twice()
            ->andReturnUsing(function (string $client, $title, $body, $data, $tokens) use (&$sent) {
                $sent[$client] = $tokens->all();

                return [];
            });

        (new SendFcmNotificationJob('T', 'B', [], $this->userAIds[0]))->handle($fcm);

        // User A's tokens across both clients were sent; user B's never.
        $allSentTokens = array_merge(...array_values($sent));
        $this->assertEqualsCanonicalizing(['token-A1', 'token-A2'], $allSentTokens);
        $this->assertNotContains('token-B1', $allSentTokens);
    }

    /** @test */
    public function job_without_target_user_never_broadcasts()
    {
        Log::spy();

        $this->seedToken($this->userAIds[0], 'token-A1');

        $fcm = Mockery::mock(FcmService::class);
        $fcm->shouldNotReceive('sendToClient');

        (new SendFcmNotificationJob('T', 'B', [], null))->handle($fcm);

        Log::shouldHaveReceived('warning')->once();
    }

    /** @test */
    public function other_users_tokens_are_untouched_by_invalid_token_cleanup()
    {
        $this->seedToken($this->userAIds[0], 'token-A-invalid');
        $this->seedToken($this->userBIds[0], 'token-B-valid');

        $fcm = Mockery::mock(FcmService::class);
        $fcm->shouldReceive('sendToClient')
            ->once()
            ->andReturnUsing(function ($client, $t, $b, $d, $tokens) {
                return $tokens->contains('token-A-invalid') ? ['token-A-invalid'] : [];
            });

        (new SendFcmNotificationJob('T', 'B', [], $this->userAIds[0]))->handle($fcm);

        $this->assertNull(DeviceToken::where('token', 'token-A-invalid')->first(), 'invalid token pruned');
        $this->assertNotNull(DeviceToken::where('token', 'token-B-valid')->first(), 'other user token untouched');
    }
}
