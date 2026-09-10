<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

/**
 * Attempts a REAL connection to Pusher using the credentials in .env and
 * verifies the exact on-wire contract (channel name, event name, JSON payload)
 * recorded by our RecordingPusher stub is accepted by Pusher's REST API.
 *
 * These tests are honesty-gated: if Pusher is unreachable they are skipped so
 * the suite stays green offline, but they never silently claim a connection
 * that did not happen. A passing run on a machine with network access proves
 * the wire payloads are valid for the production broker.
 */
class NotificationPusherIntegrationTest extends NotificationE2ETestCase
{
    private function realPusher(): ?\Pusher\Pusher
    {
        $config = config('broadcasting.connections.pusher');

        try {
            return new \Pusher\Pusher(
                $config['key'],
                $config['secret'],
                $config['app_id'],
                $config['options']
            );
        } catch (\Throwable $e) {
            return null;
        }
    }

    private function assertPusherReachable(\Pusher\Pusher $pusher): void
    {
        try {
            $pusher->get_channels();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Pusher unreachable from this environment: ' . $e->getMessage()
            );
        }
    }

    public function test_real_pusher_accepts_user_notification_wire_contract(): void
    {
        $pusher = $this->realPusher();
        $this->assertNotNull($pusher, 'Pusher client could not be constructed from config');
        $this->assertPusherReachable($pusher);

        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );

        // Push the exact recorded payload to the real broker. A non-200
        // response or connectivity failure raises ApiErrorException, so a
        // successful return proves the wire payload is accepted.
        $result = $pusher->trigger(
            $broadcast['channels'],
            $broadcast['event'],
            $broadcast['data']
        );

        $this->assertIsObject($result, 'Pusher returned a non-JSON response');
    }

    public function test_recorded_admin_event_contract_is_accepted(): void
    {
        $pusher = $this->realPusher();
        $this->assertNotNull($pusher, 'Pusher client could not be constructed from config');
        $this->assertPusherReachable($pusher);

        $admin = $this->createUser('admin');

        event(new \App\Events\AdminLoggedIn($admin, '10.0.0.1', 'Agent/2.0'));

        $broadcast = $this->assertBroadcastTo('private-admin.notifications', 'admin.logged.in');

        $result = $pusher->trigger(
            $broadcast['channels'],
            $broadcast['event'],
            $broadcast['data']
        );

        $this->assertIsObject($result, 'Pusher rejected the recorded admin event payload');
    }

    public function test_every_recorded_broadcast_payload_is_json_serializable_for_pusher(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $this->assertNotEmpty($this->recordedBroadcasts());
        foreach ($this->recordedBroadcasts() as $broadcast) {
            $decoded = json_decode(json_encode($broadcast['data']), true);
            $this->assertIsArray($decoded, 'Broadcast data is not JSON serializable');
            $this->assertSame(JSON_ERROR_NONE, json_last_error(), 'Broadcast data produced invalid JSON');
        }
    }
}
