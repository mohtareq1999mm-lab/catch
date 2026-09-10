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
use Marvel\Database\Models\NotifyLogs;
use Tests\TestCase;

class NotifyLogsTest extends TestCase
{
    use DatabaseTransactions;

    private const PREFIX = '/api/v1';

    protected function setUp(): void
    {
        parent::setUp();

        if (!Schema::hasTable('users')) {
            $this->createTables();
        }

        if (config('database.default') === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        }

        $this->beginDatabaseTransaction();
    }

    private function createTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->boolean('is_active')->default(true);
            $table->string('type')->default('user');
            $table->string('phone_number')->nullable()->unique();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('notify_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('receiver');
            $table->foreign('receiver')->references('id')->on('users')->onDelete('cascade');
            $table->unsignedBigInteger('sender')->nullable();
            $table->foreign('sender')->references('id')->on('users')->onDelete('cascade');
            $table->text('notify_type')->nullable();
            $table->text('notify_receiver_type')->nullable();
            $table->boolean('is_read')->default(false);
            $table->text('notify_tracker')->nullable();
            $table->text('notify_text')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'mhp_model_id_model_type_index');
            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->onDelete('cascade');
            $table->primary(['permission_id', 'model_id', 'model_type'],
                'mhp_permission_model_type_primary');
        });

        Schema::create('user_profiles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('customer_id');
            $table->json('avatar')->nullable();
            $table->json('socials')->nullable();
            $table->json('notifications')->nullable();
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->onDelete('cascade');
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('cascade');
            $table->primary(['permission_id', 'role_id'],
                'role_has_permissions_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('cascade');
            $table->primary(['role_id', 'model_id', 'model_type'],
                'model_has_roles_primary');
        });

        \Spatie\Permission\Models\Permission::create([
            'name' => 'super_admin',
            'guard_name' => 'api']);
    }

    private function createUser(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => fake()->name(),
            'email' => fake()->unique()->email(),
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => fake()->unique()->phoneNumber()], $overrides));
    }

    private function createNotifyLog(User $receiver, ?User $sender = null, array $overrides = []): NotifyLogs
    {
        return NotifyLogs::create(array_merge([
            'receiver' => $receiver->id,
            'sender' => $sender?->id,
            'notify_type' => 'order',
            'notify_receiver_type' => 'customer',
            'is_read' => false,
            'notify_text' => 'Test notification',
            'notify_tracker' => 'TRK-' . fake()->uuid()], $overrides));
    }

    // ==================== AUTHENTICATION ====================

    public function test_unauthenticated_user_cannot_access_index(): void
    {
        $response = $this->getJson(self::PREFIX . '/notify-logs');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_access_show(): void
    {
        $response = $this->getJson(self::PREFIX . '/notify-logs/1');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_read_notify_log(): void
    {
        $response = $this->postJson(self::PREFIX . '/notify-log-seen', ['id' => 1]);
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_read_all_notify_logs(): void
    {
        $response = $this->postJson(self::PREFIX . '/notify-log-read-all', ['set_all_read' => true]);
        $response->assertStatus(401);
    }

    // ==================== INDEX ====================

    public function test_index_returns_own_notifications(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $sender = $this->createUser();

        $this->createNotifyLog($userA, $sender);
        $this->createNotifyLog($userA, $sender);
        $this->createNotifyLog($userB, $sender);

        Sanctum::actingAs($userA);

        $response = $this->getJson(self::PREFIX . '/notify-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('total', 2);
        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_returns_empty_when_no_notifications(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/notify-logs');

        $response->assertStatus(200);
        $response->assertJsonPath('total', 0);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_index_filters_by_notify_type(): void
    {
        $user = $this->createUser();
        $sender = $this->createUser();

        $this->createNotifyLog($user, $sender, ['notify_type' => 'order']);
        $this->createNotifyLog($user, $sender, ['notify_type' => 'message']);
        $this->createNotifyLog($user, $sender, ['notify_type' => 'order']);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/notify-logs?notify_type=order');

        $response->assertStatus(200);
        $response->assertJsonPath('total', 2);
    }

    public function test_index_does_not_expose_sensitive_data(): void
    {
        $user = $this->createUser();
        $sender = $this->createUser([
            'email' => 'sender@example.com',
            'phone_number' => '01009999999']);

        $this->createNotifyLog($user, $sender);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/notify-logs');

        $response->assertStatus(200);
        $item = $response->json('data.0');

        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('sender_user', $item);
        $this->assertArrayHasKey('id', $item['sender_user']);
        $this->assertArrayHasKey('name', $item['sender_user']);
        $this->assertArrayNotHasKey('email', $item['sender_user']);
        $this->assertArrayNotHasKey('phone_number', $item['sender_user']);
    }

    // ==================== SHOW ====================

    public function test_show_returns_own_notification(): void
    {
        $user = $this->createUser();
        $sender = $this->createUser();

        $notifyLog = $this->createNotifyLog($user, $sender);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . "/notify-logs/{$notifyLog->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('id', $notifyLog->id);
        $response->assertJsonPath('receiver', $user->id);
        $response->assertJsonPath('notify_type', 'order');
    }

    public function test_show_returns_404_for_nonexistent_notification(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/notify-logs/99999');

        $response->assertStatus(404);
    }

    // ==================== IDOR: SHOW (REGRESSION) ====================

    public function test_user_cannot_view_another_users_notification(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $sender = $this->createUser();

        $notifyLog = $this->createNotifyLog($userB, $sender);

        Sanctum::actingAs($userA);

        $response = $this->getJson(self::PREFIX . "/notify-logs/{$notifyLog->id}");

        $response->assertStatus(404);
    }

    // ==================== READ NOTIFY LOG ====================

    public function test_user_can_mark_own_notification_as_read(): void
    {
        $user = $this->createUser();
        $sender = $this->createUser();

        $notifyLog = $this->createNotifyLog($user, $sender, ['is_read' => false]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/notify-log-seen', ['id' => $notifyLog->id]);

        $response->assertStatus(200);
        $response->assertJsonPath('id', $notifyLog->id);
        $response->assertJsonPath('is_read', true);

        $this->assertEquals(1, $notifyLog->fresh()->is_read);
    }

    public function test_read_requires_id(): void
    {
        $user = $this->createUser();
        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/notify-log-seen', []);

        $response->assertStatus(422);
    }

    // ==================== IDOR: READ NOTIFY LOG (REGRESSION) ====================

    public function test_user_cannot_mark_another_users_notification_as_read(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $sender = $this->createUser();

        $notifyLog = $this->createNotifyLog($userB, $sender, ['is_read' => false]);

        Sanctum::actingAs($userA);

        $response = $this->postJson(self::PREFIX . '/notify-log-seen', ['id' => $notifyLog->id]);

        $response->assertStatus(404);

        $this->assertEquals(0, $notifyLog->fresh()->is_read);
    }

    // ==================== READ ALL NOTIFY LOGS ====================

    public function test_user_can_mark_all_own_notifications_as_read(): void
    {
        $user = $this->createUser();
        $sender = $this->createUser();

        $this->createNotifyLog($user, $sender, ['is_read' => false]);
        $this->createNotifyLog($user, $sender, ['is_read' => false]);
        $this->createNotifyLog($user, $sender, ['is_read' => false]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/notify-log-read-all', [
            'set_all_read' => true]);

        $response->assertStatus(200);
        $this->assertCount(3, $response->json());

        $this->assertEquals(0, NotifyLogs::where('receiver', $user->id)->where('is_read', false)->count());
    }

    public function test_user_can_mark_all_own_notifications_as_read_filtered_by_type(): void
    {
        $user = $this->createUser();
        $sender = $this->createUser();

        $this->createNotifyLog($user, $sender, ['notify_type' => 'order', 'is_read' => false]);
        $this->createNotifyLog($user, $sender, ['notify_type' => 'message', 'is_read' => false]);
        $this->createNotifyLog($user, $sender, ['notify_type' => 'order', 'is_read' => false]);

        Sanctum::actingAs($user);

        $response = $this->postJson(self::PREFIX . '/notify-log-read-all', [
            'set_all_read' => true,
            'notify_type' => 'order']);

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());

        $this->assertEquals(0, NotifyLogs::where('receiver', $user->id)->where('notify_type', 'order')->where('is_read', false)->count());
        $this->assertEquals(1, NotifyLogs::where('receiver', $user->id)->where('notify_type', 'message')->where('is_read', false)->count());
    }

    // ==================== IDOR: READ ALL NOTIFY LOGS (REGRESSION) ====================

    public function test_user_cannot_mark_another_users_notifications_as_read_through_receiver_injection(): void
    {
        $userA = $this->createUser();
        $userB = $this->createUser();
        $sender = $this->createUser();

        $this->createNotifyLog($userB, $sender, ['is_read' => false]);
        $this->createNotifyLog($userB, $sender, ['is_read' => false]);

        Sanctum::actingAs($userA);

        $response = $this->postJson(self::PREFIX . '/notify-log-read-all', [
            'set_all_read' => true,
            'receiver' => $userB->id]);

        $response->assertStatus(200);

        $this->assertEquals(2, NotifyLogs::where('receiver', $userB->id)->where('is_read', false)->count(), 'User B notifications should remain unread');
    }

    // ==================== DESTROY ====================

    public function test_non_admin_destroy_does_not_delete_notify_log(): void
    {
        $user = $this->createUser();
        $notifyLog = $this->createNotifyLog($user);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . "/notify-logs/{$notifyLog->id}");

        $this->assertNotNull($notifyLog->fresh());
    }

    public function test_super_admin_can_destroy_notify_log(): void
    {
        $user = $this->createUser();
        $user->givePermissionTo('super_admin');
        $notifyLog = $this->createNotifyLog($user);

        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . "/notify-logs/{$notifyLog->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted($notifyLog);
    }

    // ==================== JSON STRUCTURE ====================

    public function test_notify_log_response_structure(): void
    {
        $user = $this->createUser();
        $sender = $this->createUser();

        $notifyLog = $this->createNotifyLog($user, $sender);

        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . "/notify-logs/{$notifyLog->id}");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'id',
            'receiver',
            'notify_type',
            'notify_receiver_type',
            'is_read',
            'notify_tracker',
            'notify_text',
            'created_at',
            'sender',
            'sender_user' => [
                'id',
                'name']]);
    }
}
