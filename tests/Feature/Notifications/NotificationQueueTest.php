<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\OrderCreated;
use App\Listeners\SendNewOrderNotification;
use App\Listeners\SendUserOrderCreatedNotification;
use App\Notifications\AdminLoggedInNotification;
use App\Notifications\UserOrderCreatedNotification;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;

/**
 * Verifies the async chain contracts: every user/admin notification is queued
 * on 'meem-medium' and every relevant listener is dispatched as a queued
 * listener on 'meem-medium'. AdminLoggedInNotification deliberately uses the
 * higher-priority 'meem-high' queue.
 */
class NotificationQueueTest extends NotificationE2ETestCase
{
    public function test_order_created_listeners_are_queued_on_meem_medium(): void
    {
        Queue::fake();

        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        event(new OrderCreated($order));

        Queue::assertPushedOn(
            'meem-medium',
            CallQueuedListener::class,
            fn ($job) => $job->class === SendUserOrderCreatedNotification::class
        );
        Queue::assertPushedOn(
            'meem-medium',
            CallQueuedListener::class,
            fn ($job) => $job->class === SendNewOrderNotification::class
        );
    }

    public function test_user_notification_is_queued_on_meem_medium(): void
    {
        Queue::fake();

        $user = $this->createUser('user');
        $order = $this->createOrder($user);

        $user->notify(new UserOrderCreatedNotification($order));

        Queue::assertPushedOn(
            'meem-medium',
            SendQueuedNotifications::class,
            fn ($job) => $job->displayName() === UserOrderCreatedNotification::class
        );

        // The notification instance itself carries the queue contract.
        $notification = new UserOrderCreatedNotification($order);
        $this->assertEquals('meem-medium', $notification->queue);
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
    }

    public function test_admin_login_notification_uses_meem_high_queue(): void
    {
        Queue::fake();

        $admin = $this->createUser('admin');

        $admin->notify(new AdminLoggedInNotification($admin, '127.0.0.1', 'Agent/1.0'));

        Queue::assertPushedOn(
            'meem-high',
            SendQueuedNotifications::class,
            fn ($job) => $job->displayName() === AdminLoggedInNotification::class
        );

        $notification = new AdminLoggedInNotification($admin, '127.0.0.1', 'Agent/1.0');
        $this->assertEquals('meem-high', $notification->queue);
    }

    public function test_all_user_notifications_are_queued_on_meem_medium(): void
    {
        $order = $this->createOrder($this->createUser('user'));
        $coupon = $this->createCoupon();
        $assignment = $this->createCouponAssignment($coupon, $this->createUser('user'));
        $product = $this->createProduct();
        $promotion = $this->createPromotion();
        $flashSale = $this->createFlashSale();
        $review = $this->createReview($this->createUser('user'), $product);
        $couponUser = $this->createUser('user');
        $couponOrder = $this->createOrder($couponUser);

        $notifications = [
            new \App\Notifications\UserOrderCreatedNotification($order),
            new \App\Notifications\UserOrderCancelledNotification($order),
            new \App\Notifications\UserOrderDeliveredNotification($order),
            new \App\Notifications\UserOrderRefundedNotification($this->createRefund($this->createUser('user'), $order)),
            new \App\Notifications\UserPaymentSucceededNotification($order),
            new \App\Notifications\UserPaymentFailedNotification($order),
            new \App\Notifications\UserCouponAssignedNotification($assignment),
            new \App\Notifications\UserCouponAvailableNotification($coupon),
            new \App\Notifications\UserCouponUsedNotification($coupon, $assignment, $couponUser, $couponOrder, 0, now()),
            new \App\Notifications\UserProductPriceDropNotification($product),
            new \App\Notifications\UserProductBackInStockNotification($product),
            new \App\Notifications\UserProductDiscountChangedNotification($product),
            new \App\Notifications\UserPromotionAvailableNotification($promotion),
            new \App\Notifications\UserPromotionPriceDropNotification($promotion),
            new \App\Notifications\UserFlashSaleAvailableNotification($flashSale),
            new \App\Notifications\UserFlashSalePriceDropNotification($flashSale),
            new \App\Notifications\UserReviewApprovedNotification($review),
            new \App\Notifications\UserReviewRejectedNotification($review),
        ];

        foreach ($notifications as $notification) {
            $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldQueue::class, $notification);
            $this->assertEquals(
                'meem-medium',
                $notification->queue,
                get_class($notification) . ' must run on meem-medium'
            );
        }
    }
}
