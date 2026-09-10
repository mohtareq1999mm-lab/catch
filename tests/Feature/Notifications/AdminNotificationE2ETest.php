<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\AdminLoggedIn;
use App\Events\ContactMessageReceived;
use App\Events\OrderCreated;
use App\Notifications\AdminLoggedInNotification;
use App\Notifications\NewContactMessageNotification;
use App\Notifications\NewOrderNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Laravel\Sanctum\Sanctum;

/**
 * Real pipeline for admin-facing notifications:
 *  - AdminLoggedIn (ShouldBroadcast) -> SendAdminLoginNotification -> all
 *    active admins receive AdminLoggedInNotification; the event also
 *    broadcasts to the admin.notifications private channel.
 *  - ContactMessageReceived -> SendContactMessageNotification -> all active
 *    admins receive NewContactMessageNotification.
 */
class AdminNotificationE2ETest extends NotificationE2ETestCase
{
    public function test_admin_logged_in_notifies_active_admins_in_db_and_broadcast(): void
    {
        $adminA = $this->createUser('admin');
        $adminB = $this->createUser('admin');
        $inactiveAdmin = $this->createUser('admin', ['is_active' => false]);

        event(new AdminLoggedIn($adminA, '127.0.0.1', 'Test-Agent/1.0'));

        foreach ([$adminA, $adminB] as $admin) {
            $this->assertDatabaseNotification(
                $admin,
                'admin.login',
                function ($n) use ($adminA) {
                    $this->assertEquals($adminA->id, $n->data['admin_id']);
                    $this->assertEquals('127.0.0.1', $n->data['login_ip']);
                    $this->assertEquals('Test-Agent/1.0', $n->data['user_agent']);
                }
            );
            $this->assertBroadcastTo('private-admin.notifications', 'admin.login');
        }

        $this->assertNoDatabaseNotification($inactiveAdmin, 'admin.login');
    }

    public function test_admin_logged_in_event_broadcasts_to_admin_notifications_channel(): void
    {
        $admin = $this->createUser('admin');

        event(new AdminLoggedIn($admin, '127.0.0.1', 'Test-Agent/1.0'));

        $broadcast = $this->assertBroadcastTo('private-admin.notifications', 'admin.logged.in');
        $this->assertEquals($admin->id, $broadcast['data']['id']);
        $this->assertEquals($admin->name, $broadcast['data']['name']);
    }

    public function test_contact_message_received_notifies_active_admins_in_db_and_broadcast(): void
    {
        $admin = $this->createUser('admin');
        $inactiveAdmin = $this->createUser('admin', ['is_active' => false]);
        $contact = $this->createContact(['subject' => 'Order Help']);

        event(new ContactMessageReceived($contact));

        $this->assertDatabaseNotification(
            $admin,
            'contact.message',
            function ($n) use ($contact) {
                $this->assertEquals($contact->id, $n->data['contact_id']);
                $this->assertEquals($contact->name, $n->data['customer_name']);
                $this->assertEquals('Order Help', $n->data['subject']);
            }
        );
        $broadcast = $this->assertBroadcastTo('private-admin.notifications', 'contact.message');
        $this->assertEquals('contact.message', $broadcast['data']['type']);

        $this->assertNoDatabaseNotification($inactiveAdmin, 'contact.message');
    }

    /**
     * Defect #2 fix: the stored database type must be the stable business
     * identifier returned by databaseType(), and it must equal the broadcast
     * type — never the PHP FQCN.
     */
    public function test_admin_notification_database_type_matches_broadcast_type(): void
    {
        $adminA = $this->createUser('admin');

        event(new AdminLoggedIn($adminA, '127.0.0.1', 'Agent/1.0'));

        $notification = $this->assertDatabaseNotification($adminA, 'admin.login');

        $this->assertEquals('admin.login', $notification->type);
        $this->assertNotEquals(AdminLoggedInNotification::class, $notification->type);

        $broadcast = $this->assertBroadcastTo(
            'private-admin.notifications',
            'admin.login'
        );
        $this->assertEquals('admin.login', $broadcast['data']['type']);
        $this->assertSame($notification->type, $broadcast['data']['type']);
    }

    /**
     * Defect #2B fix: admin notification payloads carry the same en/ar locale
     * structure as user notifications, stored in the database.
     */
    public function test_admin_notification_payloads_are_localized_in_db(): void
    {
        $admin = $this->createUser('admin');
        $user = $this->createUser('user');
        $contact = $this->createContact(['name' => 'Sara Ahmed']);

        event(new ContactMessageReceived($contact));

        $notification = $this->assertDatabaseNotification($admin, 'contact.message');

        $this->assertIsArray($notification->data['title']);
        $this->assertArrayHasKey('en', $notification->data['title']);
        $this->assertArrayHasKey('ar', $notification->data['title']);
        $this->assertIsArray($notification->data['message']);
        $this->assertArrayHasKey('en', $notification->data['message']);
        $this->assertArrayHasKey('ar', $notification->data['message']);

        $this->assertEquals('New contact message', $notification->data['title']['en']);
        $this->assertEquals('رسالة تواصل جديدة', $notification->data['title']['ar']);
        $this->assertEquals(
            'A new contact message was received from Sara Ahmed.',
            $notification->data['message']['en']
        );
        $this->assertEquals(
            'تم استلام رسالة تواصل جديدة من Sara Ahmed.',
            $notification->data['message']['ar']
        );
    }

    /**
     * Defect #2B: both locales resolve through the real API depending on the
     * resolved application locale.
     */
    public function test_admin_notification_locale_resolves_via_api(): void
    {
        $admin = $this->createUser('admin');
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        Sanctum::actingAs($admin);

        $english = $this->getJson(self::API_PREFIX . '/notifications')->json('data.data.0.title');
        $arabic = $this->withHeader('lang', 'ar')->getJson(self::API_PREFIX . '/notifications')->json('data.data.0.title');

        $this->assertEquals('New order received', $english);
        $this->assertEquals('طلب جديد', $arabic);
        $this->assertNotEquals($english, $arabic);
    }

    /**
     * Defect #2/#2B contract: for every admin notification, databaseType() and
     * broadcastType() return the same stable business identifier — never a PHP
     * FQCN — so DB rows, broadcasts and API clients agree on the type.
     */
    public function test_all_admin_notifications_database_type_matches_broadcast_type(): void
    {
        $admin = $this->createUser('admin');
        $user = $this->createUser('user');
        $order = $this->createOrder($user);
        $contact = $this->createContact();

        $notifications = [
            new NewOrderNotification($order),
            new NewContactMessageNotification($contact),
            new AdminLoggedInNotification($admin, '127.0.0.1', 'Agent/1.0'),
        ];

        foreach ($notifications as $notification) {
            $dbType = $notification->databaseType($admin);
            $broadcastType = $notification->broadcastType();

            $this->assertSame(
                $broadcastType,
                $dbType,
                get_class($notification) . ': databaseType() must equal broadcastType()'
            );
            $this->assertStringNotContainsString('\\', $dbType, get_class($notification) . ': type must not be an FQCN');
            $this->assertMatchesRegularExpression('/^[a-z0-9_.]+$/', $dbType);
        }
    }

    /**
     * Defect #2B: both locales resolve at runtime via app()->setLocale() from
     * the stored en/ar map.
     */
    public function test_admin_notification_localization_keys_resolve_at_runtime(): void
    {
        $admin = $this->createUser('admin');
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        app()->setLocale('en');
        event(new OrderCreated($order));

        $notification = $this->assertDatabaseNotification($admin, 'order.created');

        $this->assertEquals('New order received', $notification->data['title'][app()->getLocale()]);
        $this->assertEquals(
            "New order #{$order->order_number} has been placed.",
            $notification->data['message'][app()->getLocale()]
        );

        app()->setLocale('ar');
        $this->assertEquals('طلب جديد', $notification->data['title'][app()->getLocale()]);
        $this->assertEquals(
            "تم تقديم طلب جديد رقم {$order->order_number}.",
            $notification->data['message'][app()->getLocale()]
        );
    }
}
