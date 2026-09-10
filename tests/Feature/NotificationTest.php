<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\OrderCreated;
use App\Events\ContactMessageReceived;
use App\Events\AdminLoggedIn;
use Marvel\Database\Models\Contact;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Notifications\DatabaseNotification;
use Laravel\Sanctum\Sanctum;
use Marvel\Database\Models\Order;
use Marvel\Database\Models\User;
use Marvel\Enums\Permission as PermissionEnum;
use Marvel\Enums\Role as RoleEnum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationTest extends TestCase
{

    use DatabaseTransactions;

    private const GUARD = 'api';
    private const PREFIX = '/api/v1/admin';

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
            $table->string('phone_number')->unique();
            $table->unsignedBigInteger('shop_id')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('display_name');
            $table->string('guard_name');
            $table->timestamps();
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');
            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->onDelete('cascade');
            $table->primary(['permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary');
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');
            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->onDelete('cascade');
            $table->primary(['role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary');
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
                'role_has_permissions_permission_id_role_id_primary');
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });

        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->string('subject');
            $table->text('message');
            $table->boolean('is_read')->default(false);
            $table->boolean('is_replay')->default(false);
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('governorate_id')->nullable();
            $table->string('order_number')->nullable();
            $table->string('name')->nullable();
            $table->string('status')->default('pending');
            $table->string('payment_status')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->decimal('total_price', 10, 2)->nullable();
            $table->softDeletes();
            $table->timestamp('inventory_restored_at')->nullable();
                                    $table->string('inventory_state', 16)->default('none');
            $table->timestamp('inventory_reserved_at')->nullable();
            $table->timestamp('reservation_expires_at')->nullable();
            $table->index(['status', 'reservation_expires_at']);
            $table->timestamps();
        });

        Schema::create('activity_log', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('log_name')->nullable();
            $table->text('description');
            $table->nullableMorphs('subject', 'subject');
            $table->nullableMorphs('causer', 'causer');
            $table->string('event')->nullable();
            $table->json('properties')->nullable();
            $table->uuid('batch_uuid')->nullable();
            $table->timestamps();
            $table->index('log_name');
        });
        // FCM channel resolves user device tokens during notification fanout.
        if (!Schema::hasTable('device_tokens')) {
            Schema::create('device_tokens', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
                $table->string('token')->unique();
                $table->string('device_type')->nullable();
                $table->timestamps();
            });
        }

    }

    private function createSuperAdmin(): User
    {
        Permission::findOrCreate(self::GUARD);
        Permission::findOrCreate(PermissionEnum::VIEW_NOTIFICATIONS, self::GUARD);
        Permission::findOrCreate(PermissionEnum::MANAGE_NOTIFICATIONS, self::GUARD);

        $role = Role::create([
            'name' => RoleEnum::SUPER_ADMIN,
            'display_name' => json_encode(['en' => 'Super Admin', 'ar' => 'مدير النظام']),
            'guard_name' => self::GUARD]);
        $role->givePermissionTo([
            PermissionEnum::VIEW_NOTIFICATIONS,
            PermissionEnum::MANAGE_NOTIFICATIONS]);

        $user = User::create([
            'name' => 'Super Admin',
            'email' => 'superadmin@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '01000000001']);

        $user->assignRole($role);
        return $user;
    }

    private function createAdminWithViewOnly(): User
    {
        Permission::findOrCreate(PermissionEnum::VIEW_NOTIFICATIONS, self::GUARD);

        $user = User::create([
            'name' => 'View Only Admin',
            'email' => 'viewonly@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '01000000002']);

        $user->givePermissionTo(PermissionEnum::VIEW_NOTIFICATIONS);

        return $user;
    }

    private function createRegularUser(): User
    {
        $user = User::create([
            'name' => 'Regular User',
            'email' => 'user@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'user',
            'phone_number' => '01000000003']);

        return $user;
    }

    private function createNotification(User $user, bool $read = false): DatabaseNotification
    {
        $notification = DatabaseNotification::create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => 'App\Notifications\NewOrderNotification',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => [
                'title' => 'Test Notification',
                'message' => 'Test message.',
                'icon' => 'bell',
                'resource_type' => 'test',
                'resource_id' => 1,
                'action_url' => '/admin/test'],
            'read_at' => $read ? now() : null]);

        return $notification;
    }

    // ==================== AUTHENTICATION ====================

    public function test_unauthenticated_user_cannot_access_notifications(): void
    {
        $response = $this->getJson(self::PREFIX . '/notifications');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_access_unread(): void
    {
        $response = $this->getJson(self::PREFIX . '/notifications/unread');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_mark_as_read(): void
    {
        $response = $this->patchJson(self::PREFIX . '/notifications/fake-id/read');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_mark_all_as_read(): void
    {
        $response = $this->patchJson(self::PREFIX . '/notifications/read-all');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_delete_notification(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/notifications/fake-id');
        $response->assertStatus(401);
    }

    public function test_unauthenticated_user_cannot_delete_all_notifications(): void
    {
        $response = $this->deleteJson(self::PREFIX . '/notifications');
        $response->assertStatus(401);
    }

    // ==================== AUTHORIZATION ====================

    public function test_non_admin_cannot_access_notifications(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/notifications');
        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_mark_as_read(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);

        $response = $this->patchJson(self::PREFIX . '/notifications/fake-id/read');
        $response->assertStatus(403);
    }

    public function test_non_admin_cannot_delete(): void
    {
        $user = $this->createRegularUser();
        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . '/notifications/fake-id');
        $response->assertStatus(403);
    }

    // ==================== PERMISSIONS ====================

    public function test_admin_without_view_permission_cannot_fetch_notifications(): void
    {
        $user = User::create([
            'name' => 'No Perm Admin',
            'email' => 'noperm@example.com',
            'password' => Hash::make('password'),
            'email_verified_at' => now(),
            'is_active' => true,
            'type' => 'admin',
            'phone_number' => '01000000004']);
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/notifications');
        $response->assertStatus(403);
    }

    public function test_admin_with_view_only_cannot_mark_as_read(): void
    {
        $user = $this->createAdminWithViewOnly();
        Sanctum::actingAs($user);

        $notification = $this->createNotification($user);
        $response = $this->patchJson(self::PREFIX . "/notifications/{$notification->id}/read");
        $response->assertStatus(403);
    }

    public function test_admin_with_view_only_cannot_delete(): void
    {
        $user = $this->createAdminWithViewOnly();
        Sanctum::actingAs($user);

        $notification = $this->createNotification($user);
        $response = $this->deleteJson(self::PREFIX . "/notifications/{$notification->id}");
        $response->assertStatus(403);
    }

    public function test_admin_with_view_only_cannot_mark_all_as_read(): void
    {
        $user = $this->createAdminWithViewOnly();
        Sanctum::actingAs($user);

        $response = $this->patchJson(self::PREFIX . '/notifications/read-all');
        $response->assertStatus(403);
    }

    public function test_admin_with_view_only_cannot_delete_all(): void
    {
        $user = $this->createAdminWithViewOnly();
        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . '/notifications');
        $response->assertStatus(403);
    }

    // ==================== FETCH NOTIFICATIONS ====================

    public function test_admin_can_fetch_notifications(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user);
        $this->createNotification($user);

        $response = $this->getJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => ['id', 'type', 'title', 'message', 'icon', 'resource_type', 'resource_id', 'action_url', 'created_at', 'read_at']],
                'meta' => ['current_page', 'per_page', 'total', 'last_page', 'from', 'to']]]);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 2);
    }

    public function test_fetch_notifications_returns_empty_when_no_notifications(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $response = $this->getJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 0);
        $response->assertJsonPath('data.data', []);
    }

    public function test_fetch_notifications_only_returns_own_notifications(): void
    {
        $admin1 = $this->createSuperAdmin();
        $admin2 = $this->createAdminWithViewOnly();
        Permission::findOrCreate(PermissionEnum::MANAGE_NOTIFICATIONS, self::GUARD);
        $admin2->givePermissionTo(PermissionEnum::MANAGE_NOTIFICATIONS);

        Sanctum::actingAs($admin1);

        $this->createNotification($admin1);
        $this->createNotification($admin2);

        $response = $this->getJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('data.meta.total', 1);
    }

    public function test_fetch_notifications_supports_pagination(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        for ($i = 0; $i < 5; $i++) {
            $this->createNotification($user);
        }

        $response = $this->getJson(self::PREFIX . '/notifications?per_page=2');

        $response->assertStatus(200);
        $response->assertJsonPath('data.meta.per_page', 2);
        $response->assertJsonPath('data.meta.total', 5);
        $response->assertJsonPath('data.meta.last_page', 3);
        $this->assertCount(2, $response->json('data.data'));
    }

    // ==================== UNREAD ====================

    public function test_admin_can_fetch_unread_notifications(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user, false);
        $this->createNotification($user, false);
        $this->createNotification($user, true);

        $response = $this->getJson(self::PREFIX . '/notifications/unread');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 2);
    }

    public function test_fetch_unread_returns_empty_when_all_read(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user, true);
        $this->createNotification($user, true);

        $response = $this->getJson(self::PREFIX . '/notifications/unread');

        $response->assertStatus(200);
        $response->assertJsonPath('data.meta.total', 0);
        $response->assertJsonPath('data.data', []);
    }

    // ==================== MARK AS READ ====================

    public function test_admin_can_mark_notification_as_read(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $notification = $this->createNotification($user, false);

        $response = $this->patchJson(self::PREFIX . "/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('message', __('message.MESSAGE.NOTIFICATION_MARKED_READ'));
        $response->assertJsonPath('data.id', $notification->id);
        $this->assertNotNull($response->json('data.read_at'));
    }

    public function test_mark_as_read_is_idempotent(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $notification = $this->createNotification($user, true);

        $response = $this->patchJson(self::PREFIX . "/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
    }

    public function test_mark_as_read_returns_404_for_nonexistent_notification(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $response = $this->patchJson(self::PREFIX . '/notifications/nonexistent-id/read');

        $response->assertStatus(404);
    }

    // ==================== MARK ALL AS READ ====================

    public function test_admin_can_mark_all_notifications_as_read(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user, false);
        $this->createNotification($user, false);
        $this->createNotification($user, false);

        $response = $this->patchJson(self::PREFIX . '/notifications/read-all');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.marked_count', 3);

        $unreadCount = $user->unreadNotifications()->count();
        $this->assertEquals(0, $unreadCount);
    }

    public function test_mark_all_as_read_when_none_unread(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user, true);

        $response = $this->patchJson(self::PREFIX . '/notifications/read-all');

        $response->assertStatus(200);
        $response->assertJsonPath('data.marked_count', 0);
    }

    // ==================== DELETE ====================

    public function test_admin_can_delete_single_notification(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $notification = $this->createNotification($user);

        $response = $this->deleteJson(self::PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $this->assertNull(DatabaseNotification::find($notification->id));
    }

    public function test_delete_returns_404_for_nonexistent_notification(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . '/notifications/nonexistent-id');

        $response->assertStatus(404);
    }

    // ==================== DELETE ALL ====================

    public function test_admin_can_delete_all_notifications(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user);
        $this->createNotification($user);
        $this->createNotification($user);

        $response = $this->deleteJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.deleted_count', 3);

        $this->assertEquals(0, $user->notifications()->count());
    }

    public function test_delete_all_when_no_notifications(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $response = $this->deleteJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 0);
    }

    public function test_delete_all_only_deletes_own_notifications(): void
    {
        $admin1 = $this->createSuperAdmin();
        $admin2 = $this->createAdminWithViewOnly();
        Permission::findOrCreate(PermissionEnum::MANAGE_NOTIFICATIONS, self::GUARD);
        $admin2->givePermissionTo(PermissionEnum::MANAGE_NOTIFICATIONS);

        Sanctum::actingAs($admin1);

        $this->createNotification($admin1);
        $this->createNotification($admin1);
        $this->createNotification($admin2);

        $response = $this->deleteJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('data.deleted_count', 2);

        $this->assertEquals(0, $admin1->notifications()->count());
        $this->assertEquals(1, $admin2->notifications()->count());
    }

    // ==================== EVENT-DRIVEN NOTIFICATIONS ====================

    public function test_order_created_event_creates_notification_for_admins(): void
    {
        $admin = $this->createSuperAdmin();

        $order = Order::create([
            'user_id' => $admin->id,
            'order_number' => 'ORD-00000001',
            'status' => 'pending',
            'payment_status' => 'pending']);

        OrderCreated::dispatch($order);

        $this->assertEquals(1, $admin->notifications()->count());
        $notification = $admin->notifications()->first();
        $this->assertEquals('order.created', $notification->type);
        $this->assertEquals('New order received', $notification->data['title']['en']);
        $this->assertEquals('طلب جديد', $notification->data['title']['ar']);
        $this->assertEquals('order', $notification->data['resource_type']);
    }

    public function test_contact_message_event_creates_notification_for_admins(): void
    {
        $admin = $this->createSuperAdmin();

        $contact = Contact::create([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'subject' => 'Test Subject',
            'message' => 'Test message body']);

        ContactMessageReceived::dispatch($contact);

        $this->assertEquals(1, $admin->notifications()->count());
        $notification = $admin->notifications()->first();
        $this->assertEquals('contact.message', $notification->type);
        $this->assertEquals('New contact message', $notification->data['title']['en']);
        $this->assertEquals('رسالة تواصل جديدة', $notification->data['title']['ar']);
        $this->assertEquals('contact', $notification->data['resource_type']);
    }

    public function test_admin_login_event_creates_notification_for_admins(): void
    {
        $admin = $this->createSuperAdmin();

        AdminLoggedIn::dispatch($admin, '127.0.0.1', 'TestAgent/1.0');

        $this->assertEquals(1, $admin->notifications()->count());
        $notification = $admin->notifications()->first();
        $this->assertEquals('admin.login', $notification->type);
        $this->assertEquals('Admin login', $notification->data['title']['en']);
        $this->assertEquals('تسجيل دخول المشرف', $notification->data['title']['ar']);
        $this->assertEquals('admin', $notification->data['resource_type']);
    }

    // ==================== JSON STRUCTURE ====================

    public function test_notification_response_structure(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user);

        $response = $this->getJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'title',
                        'message',
                        'icon',
                        'resource_type',
                        'resource_id',
                        'action_url',
                        'created_at',
                        'read_at']],
                'meta' => [
                    'current_page',
                    'per_page',
                    'total',
                    'last_page',
                    'from',
                    'to']]]);
    }

    public function test_unread_response_structure(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user, false);

        $response = $this->getJson(self::PREFIX . '/notifications/unread');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'success',
            'message',
            'data' => [
                'data' => [
                    '*' => [
                        'id',
                        'type',
                        'title',
                        'message',
                        'icon',
                        'resource_type',
                        'resource_id',
                        'action_url',
                        'created_at',
                        'read_at']],
                'meta' => [
                    'total']]]);
    }

    public function test_mark_as_read_response_structure(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $notification = $this->createNotification($user, false);

        $response = $this->patchJson(self::PREFIX . "/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'success',
            'message',
            'data' => [
                'id',
                'type',
                'title',
                'message',
                'icon',
                'resource_type',
                'resource_id',
                'action_url',
                'created_at',
                'read_at']]);
    }

    public function test_mark_all_as_read_response_structure(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user);

        $response = $this->patchJson(self::PREFIX . '/notifications/read-all');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'success',
            'message',
            'data' => [
                'marked_count']]);
    }

    public function test_delete_all_response_structure(): void
    {
        $user = $this->createSuperAdmin();
        Sanctum::actingAs($user);

        $this->createNotification($user);

        $response = $this->deleteJson(self::PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonStructure([
            'status',
            'success',
            'message',
            'data' => [
                'deleted_count']]);
    }
}
