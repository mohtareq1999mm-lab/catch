<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCancelled;
use App\Events\OrderCreated;
use App\Events\PaymentFailed;
use App\Events\PaymentSucceeded;
use App\Events\RefundApproved;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;
use Marvel\Events\OrderDelivered;

/**
 * Real pipeline: OrderCreated / PaymentSucceeded / PaymentFailed /
 * OrderCancelled / OrderDelivered / RefundApproved -> real listeners ->
 * real Notification (database + broadcast) -> notifications table ->
 * PusherBroadcaster -> RecordingPusher.
 */
class OrderNotificationE2ETest extends NotificationE2ETestCase
{
    public function test_order_created_flow_notifies_user_and_admin_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $admin = $this->createUser('admin');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $userNotification = $this->assertDatabaseNotification(
            $user,
            'order.created',
            function ($n) use ($order) {
                $this->assertEquals('order.created', $n->type);
                $this->assertEquals('en', array_key_first($n->data['title']));
                $this->assertEquals($order->id, $n->data['resource_id']);
            }
        );

        // Admin receives the admin notification; after Defect #2 its DB type is
        // the stable business identifier 'order.created', same as its broadcast
        // type — never the PHP FQCN.
        $this->assertDatabaseNotification($admin, 'order.created', function ($n) use ($order) {
            $this->assertEquals('order.created', $n->type);
            $this->assertEquals($order->order_number, $n->data['order_number']);
        });

        // User notification was broadcast to private-users.{id}.
        $userBroadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.created'
        );
        $this->assertEquals('order.created', $userBroadcast['data']['type']);
        $this->assertEquals('en', array_key_first($userBroadcast['data']['title']));
        $this->assertEquals($userNotification->id, $userBroadcast['data']['id']);

        // Admin notification was broadcast to private-admin.notifications.
        $adminBroadcast = $this->assertBroadcastTo(
            'private-admin.notifications',
            'order.created'
        );
        $this->assertEquals('order.created', $adminBroadcast['data']['type']);
    }

    public function test_order_created_does_not_notify_inactive_admin(): void
    {
        $user = $this->createUser('user');
        $inactiveAdmin = $this->createUser('admin', ['is_active' => false]);
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        $this->assertDatabaseNotification($user, 'order.created');
        $this->assertNoDatabaseNotification($inactiveAdmin, 'order.created');
    }

    public function test_payment_succeeded_flow_notifies_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new PaymentSucceeded($order));

        $notification = $this->assertDatabaseNotification(
            $user,
            'payment.succeeded',
            function ($n) {
                $this->assertEquals('payment.succeeded', $n->type);
            }
        );

        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'payment.succeeded'
        );
        $this->assertEquals('payment.succeeded', $broadcast['data']['type']);
        $this->assertEquals($notification->id, $broadcast['data']['id']);
    }

    public function test_payment_failed_flow_notifies_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new PaymentFailed($order));

        $this->assertDatabaseNotification($user, 'payment.failed');
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'payment.failed'
        );
        $this->assertEquals('payment.failed', $broadcast['data']['type']);
    }

    public function test_order_cancelled_flow_notifies_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCancelled($order));

        $this->assertDatabaseNotification($user, 'order.cancelled');
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.cancelled'
        );
        $this->assertEquals('order.cancelled', $broadcast['data']['type']);
    }

    public function test_order_delivered_flow_notifies_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        // The legacy Marvel SendOrderDeliveredNotification calls
        // getWhichUserWillGetEmail(..., $order->language) with a string-typed
        // $language. A delivered order carries its locale, so simulate it.
        $order->language = 'en';
        $this->ensureSuperAdminPermission();

        event(new OrderDelivered($order));

        $this->assertDatabaseNotification($user, 'order.delivered');
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.delivered'
        );
        $this->assertEquals('order.delivered', $broadcast['data']['type']);
    }

    public function test_refund_approved_flow_notifies_user_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $order = $this->createOrder($user);
        $refund = $this->createRefund($user, $order);

        event(new RefundApproved($refund));

        $this->assertDatabaseNotification($user, 'order.refunded');
        $broadcast = $this->assertBroadcastTo(
            'private-users.' . $user->id,
            'order.refunded'
        );
        $this->assertEquals('order.refunded', $broadcast['data']['type']);
    }

    public function test_refund_approved_does_not_notify_admin_customer(): void
    {
        $admin = $this->createUser('admin');
        $order = $this->createOrder($admin);
        $refund = $this->createRefund($admin, $order);

        event(new RefundApproved($refund));

        $this->assertNoDatabaseNotification($admin, 'order.refunded');
    }
}
