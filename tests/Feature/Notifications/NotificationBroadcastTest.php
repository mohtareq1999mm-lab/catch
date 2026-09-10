<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\AdminLoggedIn;
use App\Events\OrderCreated;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

/**
 * Verifies the exact on-wire broadcast contract that Pusher receives for both
 * user notifications and admin-level events. The real PusherBroadcaster turns
 * PrivateChannel('users.{id}') into 'private-users.{id}' and PrivateChannel
 * ('admin.notifications') into 'private-admin.notifications'.
 */
class NotificationBroadcastTest extends NotificationE2ETestCase
{
    public function test_user_notification_wire_contract(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );

        // Channel + event name.
        $this->assertEquals(['private-users.' . $user->id], $broadcast['channels']);
        $this->assertEquals('order.created', $broadcast['event']);

        // Payload: stable business type + notification id + localized maps.
        $data = $broadcast['data'];
        $this->assertEquals('order.created', $data['type']);
        $this->assertArrayHasKey('id', $data);
        $this->assertIsString($data['id']);
        $this->assertIsArray($data['title']);
        $this->assertArrayHasKey('en', $data['title']);
        $this->assertArrayHasKey('ar', $data['title']);
        $this->assertIsArray($data['message']);
        $this->assertArrayHasKey('en', $data['message']);
        $this->assertArrayHasKey('ar', $data['message']);
        $this->assertEquals('order', $data['resource_type']);
        $this->assertEquals($order->id, $data['resource_id']);
    }

    public function test_admin_event_wire_contract(): void
    {
        $admin = $this->createUser('admin');

        event(new AdminLoggedIn($admin, '10.0.0.1', 'Agent/2.0'));

        $broadcast = $this->assertBroadcastTo('private-admin.notifications', 'admin.logged.in');

        $this->assertEquals(['private-admin.notifications'], $broadcast['channels']);
        $this->assertEquals($admin->id, $broadcast['data']['id']);
        $this->assertEquals($admin->name, $broadcast['data']['name']);
        $this->assertEquals($admin->email, $broadcast['data']['email']);
        $this->assertEquals('admin', $broadcast['data']['type']);
        $this->assertArrayHasKey('login_time', $broadcast['data']);
    }

    public function test_broadcast_payload_is_pusher_compatible(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        foreach ($this->recordedBroadcasts() as $broadcast) {
            foreach ($broadcast['channels'] as $channel) {
                // Matches the pusher-php-server client-side validation rule.
                $this->assertMatchesRegularExpression(
                    '/\A#?[-a-zA-Z0-9_=@,.;]+\z/',
                    $channel,
                    "Invalid Pusher channel name: {$channel}"
                );
            }
            // Every notification broadcasts with its own stable event name
            // (broadcastAs() returns broadcastType(), e.g. 'order.created')
            // instead of a shared FQCN, so clients can listen per type.
            $this->assertLessThanOrEqual(200, strlen($broadcast['event']));
            $this->assertIsArray($broadcast['data']);
        }
    }
}
