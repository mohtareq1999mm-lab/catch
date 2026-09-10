<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use App\Events\PaymentSucceeded;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

/**
 * REST API over the real notification pipeline: dispatch a domain event,
 * verify the notification lands in the database, then verify the
 * /api/v1/notifications endpoints expose it to the owning user only.
 */
class NotificationApiE2ETest extends NotificationE2ETestCase
{
    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson(self::API_PREFIX . '/notifications')->assertStatus(401);
        $this->getJson(self::API_PREFIX . '/notifications/unread')->assertStatus(401);
        $this->getJson(self::API_PREFIX . '/notifications/fake')->assertStatus(401);
        $this->patchJson(self::API_PREFIX . '/notifications/fake/read')->assertStatus(401);
        $this->postJson(self::API_PREFIX . '/notifications/read-all')->assertStatus(401);
        $this->deleteJson(self::API_PREFIX . '/notifications/fake')->assertStatus(401);
    }

    public function test_index_returns_real_pipeline_notifications_with_pagination(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));
        event(new PaymentSucceeded($order));

        Sanctum::actingAs($user);
        $response = $this->getJson(self::API_PREFIX . '/notifications');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.meta.total', 2);

        $types = collect($response->json('data.data'))->pluck('type')->all();
        $this->assertContains('order.created', $types);
        $this->assertContains('payment.succeeded', $types);
    }

    public function test_unread_endpoint_returns_only_unread(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $notification = $this->assertDatabaseNotification($user, 'order.created');
        $notification->markAsRead();

        Sanctum::actingAs($user);
        $response = $this->getJson(self::API_PREFIX . '/notifications/unread');

        $response->assertStatus(200);
        $response->assertJsonPath('data.meta.total', 0);
    }

    public function test_show_exposes_single_notification_with_localized_fields(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $notification = $this->assertDatabaseNotification($user, 'order.created');

        Sanctum::actingAs($user);
        $response = $this->getJson(self::API_PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $notification->id);
        $response->assertJsonPath('data.type', 'order.created');
        $response->assertJsonPath('data.resource_type', 'order');
        $response->assertJsonPath('data.resource_id', $order->id);
    }

    public function test_arabic_locale_resolves_via_lang_header(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        Sanctum::actingAs($user);

        $english = $this->getJson(self::API_PREFIX . '/notifications')->json('data.data.0.title');
        $arabic = $this->withHeader('lang', 'ar')->getJson(self::API_PREFIX . '/notifications')->json('data.data.0.title');

        $this->assertIsString($english);
        $this->assertIsString($arabic);
        $this->assertNotEmpty($arabic);
        $this->assertNotEquals($english, $arabic);
    }

    public function test_mark_as_read_updates_db(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $notification = $this->assertDatabaseNotification($user, 'order.created');

        Sanctum::actingAs($user);
        $response = $this->patchJson(self::API_PREFIX . "/notifications/{$notification->id}/read");

        $response->assertStatus(200);
        $this->assertNotNull(
            DB::table('notifications')->where('id', $notification->id)->value('read_at')
        );
    }

    public function test_mark_all_as_read_marks_real_notifications(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));
        event(new PaymentSucceeded($order));

        Sanctum::actingAs($user);
        $response = $this->postJson(self::API_PREFIX . '/notifications/read-all');

        $response->assertStatus(200);
        $response->assertJsonPath('data.marked_count', 2);
        $this->assertEquals(0, $user->unreadNotifications()->count());
    }

    public function test_delete_removes_real_notification(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $notification = $this->assertDatabaseNotification($user, 'order.created');

        Sanctum::actingAs($user);
        $response = $this->deleteJson(self::API_PREFIX . "/notifications/{$notification->id}");

        $response->assertStatus(200);
        $this->assertNull(
            DB::table('notifications')->where('id', $notification->id)->first()
        );
    }

    public function test_ownership_isolation_for_real_notifications(): void
    {
        $owner = $this->createUser('user');
        $other = $this->createUser('user');
        $order = $this->createOrder($owner);

        event(new OrderCreated($order));

        $notification = $this->assertDatabaseNotification($owner, 'order.created');

        Sanctum::actingAs($other);
        $this->getJson(self::API_PREFIX . "/notifications/{$notification->id}")->assertStatus(404);
        $this->patchJson(self::API_PREFIX . "/notifications/{$notification->id}/read")->assertStatus(404);
        $this->deleteJson(self::API_PREFIX . "/notifications/{$notification->id}")->assertStatus(404);
    }

    /**
     * Defect #2 fix: the admin notification (NewOrderNotification) stores the
     * stable business identifier 'order.created' as its database type — never
     * the PHP FQCN.
     */
    public function test_admin_notification_rows_store_stable_type_not_fqcn(): void
    {
        $user = $this->createUser('user');
        $admin = $this->createUser('admin');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        Sanctum::actingAs($admin);
        $response = $this->getJson(self::API_PREFIX . '/notifications');

        $response->assertStatus(200);
        $types = collect($response->json('data.data'))->pluck('type')->all();
        $this->assertContains('order.created', $types);
        $this->assertNotContains(\App\Notifications\NewOrderNotification::class, $types);
    }
}
