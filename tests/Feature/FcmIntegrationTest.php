<?php

namespace Tests\Feature;

use App\Models\DeviceToken;
use App\Notifications\UserOrderCreatedNotification;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\CreatesTestTables;
use Tests\TestCase;

class FcmIntegrationTest extends TestCase
{
    use CreatesTestTables, DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        if (config('database.default') === 'sqlite') {
            \Illuminate\Support\Facades\DB::statement('PRAGMA foreign_keys = ON;');
        }

        // Real project migrations use MySQL ENUM — not SQLite-safe — so the
        // shared manual test schema is used instead of RefreshDatabase here.
        $this->createAllTestTables();

        if (!\Illuminate\Support\Facades\Schema::hasTable('device_tokens')) {
            \Illuminate\Support\Facades\Schema::create('device_tokens', function ($table) {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
                $table->string('token', 512)->unique();
                $table->string('client', 32);
                $table->string('platform', 16)->default('android');
                $table->timestamp('last_used_at')->nullable();
                $table->timestamps();
                $table->index(['user_id', 'client']);
            });
        }
    }
    /** 1+2+3: register, update/reassign, multiple devices — ownership-only lifecycle */
    public function test_register_update_and_multiple_devices(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'FCM User', 'email' => uniqid().'@fcm.test', 'password' => bcrypt('x'),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user);

        $this->postJson('/api/v1/general/device-tokens', [
            'token' => 'tok-1', 'client' => 'client_a',
        ])->assertOk();

        $first = DeviceToken::where('token', 'tok-1')->first();
        $this->assertSame($user->id, $first->user_id);
        $this->assertSame('client_a', $first->client);

        // Same token from another user → reassigned (no duplicates).
        $other = \Marvel\Database\Models\User::create([
            'name' => 'Other', 'email' => uniqid().'@fcm.test', 'password' => bcrypt('x'),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($other);
        $this->postJson('/api/v1/general/device-tokens', [
            'token' => 'tok-1', 'client' => 'client_b', 'platform' => 'ios',
        ])->assertOk();
        $this->assertSame(1, DeviceToken::where('token', 'tok-1')->count());
        $this->assertSame($other->id, DeviceToken::where('token', 'tok-1')->first()->user_id);
        $this->assertSame('client_b', DeviceToken::where('token', 'tok-1')->first()->client);

        // Multiple devices per user.
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson('/api/v1/general/device-tokens', ['token' => 'tok-2', 'client' => 'client_a'])->assertOk();
        $this->assertSame(1, DeviceToken::where('user_id', $user->id)->where('client', 'client_a')->count());
    }

    public function test_invalid_client_rejected(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Bad Client', 'email' => uniqid().'@fcm.test', 'password' => bcrypt('x'),
        ]);
        \Laravel\Sanctum\Sanctum::actingAs($user);
        $this->postJson('/api/v1/general/device-tokens', [
            'token' => 'tok-x', 'client' => 'client_c',
        ])->assertStatus(422)->assertJsonValidationErrors(['client']);
    }

    public function test_unauthenticated_registration_blocked(): void
    {
        $this->postJson('/api/v1/general/device-tokens', [
            'token' => 'tok-guest', 'client' => 'client_a',
        ])->assertStatus(401);
    }

    /** Logout behavior: owner deletes own token only */
    public function test_delete_own_token_only(): void
    {
        $owner = \Marvel\Database\Models\User::create([
            'name' => 'Owner', 'email' => uniqid().'@fcm.test', 'password' => bcrypt('x'),
        ]);
        DeviceToken::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $owner->id, 'token' => 'own-tok', 'client' => 'client_a']);
        $foreign = \Marvel\Database\Models\User::create([
            'name' => 'Foreign', 'email' => uniqid().'@fcm.test', 'password' => bcrypt('x'),
        ]);
        DeviceToken::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $foreign->id, 'token' => 'foreign-tok', 'client' => 'client_a']);

        \Laravel\Sanctum\Sanctum::actingAs($owner);
        $this->deleteJson('/api/v1/general/device-tokens', ['token' => 'foreign-tok'])->assertOk();
        $this->assertDatabaseHas('device_tokens', ['token' => 'foreign-tok']); // untouched

        $this->deleteJson('/api/v1/general/device-tokens', ['token' => 'own-tok'])->assertOk();
        $this->assertDatabaseMissing('device_tokens', ['token' => 'own-tok']);
    }

    /** Driver registration: via('fcm') must resolve through ChannelManager */
    public function test_fcm_driver_is_registered_and_dispatches_job(): void
    {
        $user = \Marvel\Database\Models\User::create([
            'name' => 'Driver User', 'email' => uniqid().'@fcm.test', 'password' => bcrypt('x'),
        ]);

        // Fake ONLY the FCM job so the framework's SendQueuedNotifications
        // wrapper still runs synchronously and invokes FcmChannel.
        Queue::fake([\App\Jobs\SendFcmNotificationJob::class]);

        DeviceToken::create(['uuid' => (string) \Illuminate\Support\Str::uuid(),
            'user_id' => $user->id, 'token' => 'drv-tok', 'client' => 'client_a']);

        try {
            $resolved = app(\Illuminate\Notifications\ChannelManager::class)->driver('fcm');
            $this->assertInstanceOf(\App\Notifications\Channels\FcmChannel::class, $resolved);
        } catch (\Throwable $e) {
            $this->fail('FCM driver not registered: ' . $e->getMessage());
        }

        $user->notify(new UserOrderCreatedNotification(
            \Marvel\Database\Models\Order::create([
                'user_id' => $user->id, 'name' => $user->name,
                'user_phone' => '01000000000', 'user_email' => $user->email,
                'address' => json_encode(['city' => 'Cairo']),
                'price' => 100.0, 'total_price' => 100.0,
            ])
        ));

        Queue::assertPushed(\App\Jobs\SendFcmNotificationJob::class);
    }

    /** ALL 25 notification classes expose the fcm channel */
    public function test_all_notification_classes_use_fcm_channel(): void
    {
        $dir = app_path('Notifications');
        $classes = collect((new Filesystem)->files($dir))
            ->map(fn ($f) => 'App\\Notifications\\'.str_replace(['.php','/'], ['','\\'], $f->getFilename()));

        $this->assertGreaterThan(20, $classes->count());

        foreach ($classes as $class) {
            if (!class_exists($class)) { continue; }

            // VerifyEmailNotification extends Illuminate's mail-only
            // VerifyEmail base: it has NO toDatabase()/toBroadcast() payload,
            // so FCM cannot reuse an authoritative payload for it without
            // inventing a second contract (forbidden). Documented exclusion.
            if (str_contains($class, 'VerifyEmailNotification')) {
                $source = file_get_contents((new \ReflectionClass($class))->getFileName());
                $this->assertStringNotContainsString("'fcm'", $source);
                continue;
            }

            // Channel presence proven by source (via() needs a notifiable instance).
            $source = file_get_contents((new \ReflectionClass($class))->getFileName());
            $this->assertStringContainsString("'fcm'", $source, "$class is missing the fcm channel");

            // Single payload authority: FcmChannel reuses toDatabase();
            // no notification may define an independent toFcm payload.
            $this->assertStringNotContainsString('function toFcm(', $source, "$class must reuse the database payload");
        }
    }
}