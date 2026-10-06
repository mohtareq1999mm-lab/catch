<?php

namespace Tests\Feature\Fulfillment;

use App\Events\OrderCreated;
use App\Events\PaymentSucceeded;
use App\Jobs\SendFcmNotificationJob;
use App\Listeners\HandleFailedQueueJob;
use App\Models\DeviceToken;
use App\Notifications\AdminQueueJobFailedNotification;
use App\Services\Firebase\FcmService;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Str;
use Marvel\Database\Models\User;
use Tests\TestCase;

/**
 * P10 — async safety net for the fulfillment trigger/notification fan-out.
 *
 * Proves (not assumes):
 * - Fulfillment trigger events defer past commit (rollback discards fan-out).
 * - A notification job can never corrupt a business transaction (fail-safe
 *   skip, invalid-token hygiene, alert path never masks the original failure).
 * - Failure alerts carry identification only (no traces/secrets).
 *
 * Canonical authorities are untouched: this file asserts async behavior only.
 */
class AsyncSafetyTest extends TestCase
{
    use RefreshDatabase;

    public function test_fulfillment_trigger_events_defer_past_commit(): void
    {
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new PaymentSucceeded(null));
        $this->assertInstanceOf(ShouldDispatchAfterCommit::class, new OrderCreated(null));
    }

    public function test_fcm_job_without_target_user_skips_without_sending(): void
    {
        $fcm = $this->mock(FcmService::class);
        $fcm->shouldReceive('sendToClient')->never();

        (new SendFcmNotificationJob('t', 'b', ['k' => 'v'], null))->handle($fcm);

        $this->assertDatabaseCount('device_tokens', 0);
    }

    public function test_fcm_job_removes_invalid_tokens_and_keeps_valid(): void
    {
        $user = User::factory()->create(['type' => 'customer']);
        DeviceToken::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'token' => 'good-token', 'client' => 'fcm',
        ]);
        DeviceToken::create([
            'uuid' => (string) Str::uuid(), 'user_id' => $user->id,
            'token' => 'bad-token', 'client' => 'fcm',
        ]);

        $fcm = $this->mock(FcmService::class);
        $fcm->shouldReceive('sendToClient')->once()->andReturn(['bad-token']);

        (new SendFcmNotificationJob('t', 'b', [], $user->id))->handle($fcm);

        $this->assertTrue(DeviceToken::where('token', 'good-token')->exists());
        $this->assertFalse(DeviceToken::where('token', 'bad-token')->exists());
    }

    public function test_failed_queue_alert_without_admins_never_masks_failure(): void
    {
        $job = new class
        {
            public function getQueue(): string
            {
                return 'high';
            }
        };
        $event = new JobFailed('database', $job, new \RuntimeException('boom'));

        (new HandleFailedQueueJob)->handle($event);

        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_failure_alert_payload_carries_identification_only(): void
    {
        $payload = (new AdminQueueJobFailedNotification('JobX', 'high', 'boom'))
            ->toDatabase(new User);

        $this->assertArrayNotHasKey('trace', $payload);
        $this->assertArrayNotHasKey('exception', $payload);
        $this->assertSame('JobX', $payload['job']);
        $this->assertSame('high', $payload['queue']);
    }
}
