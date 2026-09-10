<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Notifications\UserOrderCreatedNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

/**
 * Pusher connection check.
 *
 * The real PusherBroadcaster stays in place while its HTTP client is swapped
 * for the RecordingPusher fake (the "fake connection"). A green run proves the
 * full app-to-Pusher contract is wired correctly: private channel name, event
 * name and JSON-serializable payload are all produced as Pusher expects.
 *
 * Run with:  php artisan test --filter=PusherConnectionCheckTest
 */
class PusherConnectionCheckTest extends NotificationE2ETestCase
{
    public function test_fake_pusher_connection_receives_user_order_notification(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        $user->notify(new UserOrderCreatedNotification($order));

        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );

        $this->assertSame('order.created', $broadcast['data']['type']);
        $this->assertArrayHasKey('id', $broadcast['data']);
        $this->assertArrayHasKey('title', $broadcast['data']);
        $this->assertArrayHasKey('message', $broadcast['data']);

        $decoded = json_decode(json_encode($broadcast['data']), true);
        $this->assertIsArray($decoded, 'Broadcast data is not JSON serializable for Pusher.');
        $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Broadcast data produced invalid JSON.');

        $this->assertDatabaseNotification($user, 'order.created');
    }

    public function test_fake_pusher_connection_records_channel_authorization(): void
    {
        $user = $this->createUser('user');

        $signature = $this->pusher?->socket_auth('private-users.' . $user->id, '123.456');

        $this->assertNotNull($this->pusher, 'RecordingPusher (fake connection) was not installed.');
        $this->assertIsString($signature);
        $this->assertJson($signature);
        $this->assertSame('private-users.' . $user->id, $this->pusher->authorizations[0]['channel'] ?? null);
    }
}
