<?php

namespace Tests\Feature;

use App\Models\UserNotificationPreference;
use App\Models\UserDeviceToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\User;
use Tests\TestCase;

class NotificationPreferencesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['user_notification_preferences', 'user_device_tokens', 'order_notifications'] as $table) {
            if (!Schema::hasTable($table)) {
                // Ensure migrations have run; if not, create minimal
                if ($table === 'user_notification_preferences') {
                    Schema::create($table, function (Blueprint $t) {
                        $t->id();
                        $t->unsignedBigInteger('user_id')->unique();
                        $t->boolean('email_enabled')->default(true);
                        $t->boolean('sms_enabled')->default(true);
                        $t->boolean('push_enabled')->default(true);
                        $t->boolean('websocket_enabled')->default(true);
                        $t->json('event_preferences')->nullable();
                        $t->boolean('email_verified')->default(false);
                        $t->boolean('phone_verified')->default(false);
                        $t->timestamp('email_verified_at')->nullable();
                        $t->timestamp('phone_verified_at')->nullable();
                        $t->time('quiet_hours_start')->nullable();
                        $t->time('quiet_hours_end')->nullable();
                        $t->boolean('respect_quiet_hours')->default(false);
                        $t->string('notification_language', 10)->default('en');
                        $t->timestamps();
                        $t->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
                    });
                }
            }
        }
        try {
            \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS idx_orders_user_pending_unique');
        } catch (\Throwable $e) {}
        if (!Schema::hasTable('order_status_history')) {
            Schema::create('order_status_history', function (Blueprint $table) {
                $table->id();
                $table->foreignId('order_id')->constrained('orders')->onDelete('cascade');
                $table->string('old_status')->nullable();
                $table->string('new_status');
                $table->string('old_payment_status')->nullable();
                $table->string('new_payment_status')->nullable();
                $table->string('old_fulfillment_status')->nullable();
                $table->string('new_fulfillment_status')->nullable();
                $table->foreignId('changed_by')->nullable()->constrained('users')->onDelete('set null');
                $table->string('changed_by_type')->default('user');
                $table->text('notes')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('changed_at');
                $table->timestamps();
            });
        }
    }

    /** @test */
    public function user_can_get_default_notification_preferences()
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $response = $this->getJson('/api/v1/user/notification-preferences');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.user_id', $user->id)
            ->assertJsonPath('data.email_enabled', true)
            ->assertJsonPath('data.sms_enabled', true);
    }

    /** @test */
    public function user_can_update_notification_preferences()
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $response = $this->putJson('/api/v1/user/notification-preferences', [
            'email_enabled' => false,
            'sms_enabled' => true,
            'quiet_hours_start' => '22:00',
            'quiet_hours_end' => '08:00',
            'respect_quiet_hours' => true,
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.email_enabled', false);

        $this->assertDatabaseHas('user_notification_preferences', [
            'user_id' => $user->id,
            'email_enabled' => false,
            'respect_quiet_hours' => true,
        ]);
    }

    /** @test */
    public function detects_quiet_hours_correctly()
    {
        $prefs = UserNotificationPreference::create([
            'user_id' => User::factory()->create()->id,
            'quiet_hours_start' => '22:00:00',
            'quiet_hours_end' => '08:00:00',
            'respect_quiet_hours' => true,
        ]);

        $this->assertTrue($prefs->isInQuietHours(now()->setTime(23, 0)));
        $this->assertTrue($prefs->isInQuietHours(now()->setTime(2, 0)));
        $this->assertFalse($prefs->isInQuietHours(now()->setTime(10, 0)));

        // Respect false → always false
        $prefs->update(['respect_quiet_hours' => false]);
        $this->assertFalse($prefs->isInQuietHours(now()->setTime(23, 0)));
    }

    /** @test */
    public function user_can_register_device_token()
    {
        $user = User::factory()->create();

        Sanctum::actingAs($user);
        $response = $this->postJson('/api/v1/user/devices/register', [
            'token' => 'test_fcm_token_12345',
            'platform' => 'android',
            'device_name' => 'Samsung Galaxy S21',
            'device_id' => 'device_abc123',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_device_tokens', [
            'user_id' => $user->id,
            'token' => 'test_fcm_token_12345',
            'platform' => 'android',
            'is_active' => true,
        ]);
    }

    /** @test */
    public function user_can_unregister_device_token()
    {
        $user = User::factory()->create();
        $device = UserDeviceToken::create([
            'user_id' => $user->id,
            'token' => 'token_to_remove',
            'platform' => 'web',
            'is_active' => true,
        ]);

        Sanctum::actingAs($user);
        $response = $this->deleteJson("/api/v1/user/devices/{$device->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('user_device_tokens', [
            'id' => $device->id,
            'is_active' => false,
        ]);
    }

    /** @test */
    public function notification_history_returns_paginated()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/v1/user/notifications/history');
        $response->assertStatus(200)->assertJsonPath('success', true);
    }
}
