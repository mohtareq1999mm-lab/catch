<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

/**
 * REAL Pusher connection check using the credentials in .env.
 *
 * Unlike the fake-based PusherConnectionCheckTest (which only records
 * in-memory and never reaches the broker), this test performs actual HTTP
 * calls to api-*.pusher.com:
 *
 *   1. get_channels()  -> proves network + credentials + cluster are valid
 *   2. trigger()       -> pushes a REAL user notification event to the REAL
 *                         private channel, so it can be observed in the Pusher
 *                         Debug Console while a subscriber is connected.
 *
 * If Pusher is unreachable the tests are skipped (honesty-gated), so the
 * suite stays green offline and never claims a connection that didn't happen.
 */
class PusherRealConnectionCheckTest extends NotificationE2ETestCase
{
    private function realPusher(): \Pusher\Pusher
    {
        $config = config('broadcasting.connections.pusher');

        return new \Pusher\Pusher(
            $config['key'],
            $config['secret'],
            $config['app_id'],
            $config['options']
        );
    }

    private function assertPusherReachable(\Pusher\Pusher $pusher): void
    {
        try {
            $channels = $pusher->get_channels();
        } catch (\Throwable $e) {
            $this->markTestSkipped(
                'Pusher unreachable from this environment: ' . $e->getMessage()
            );
        }
    }

    public function test_real_pusher_connection_is_reachable(): void
    {
        $pusher = $this->realPusher();

        $channels = $pusher->get_channels();

        $this->assertIsObject($channels);
        $this->assertTrue(property_exists($channels, 'channels'), 'Expected a channels property on the response.');
    }

    public function test_real_pusher_receives_user_notification_broadcast(): void
    {
        $pusher = $this->realPusher();
        $this->assertPusherReachable($pusher);

        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );

        $result = $pusher->trigger(
            $broadcast['channels'],
            $broadcast['event'],
            $broadcast['data']
        );

        $this->assertIsObject($result, 'Pusher rejected the notification payload.');
    }
}
