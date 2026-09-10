<?php

declare(strict_types=1);

namespace Tests\Feature\Notifications;

use App\Events\ReviewApproved;
use App\Events\ReviewRejected;
use App\Notifications\UserReviewApprovedNotification;
use App\Notifications\UserReviewRejectedNotification;
use Illuminate\Notifications\Events\BroadcastNotificationCreated;

/**
 * Real pipeline: ReviewApproved / ReviewRejected -> real listeners ->
 * real Notification (database + broadcast) -> notifications table ->
 * PusherBroadcaster -> RecordingPusher.
 */
class ReviewNotificationE2ETest extends NotificationE2ETestCase
{
    public function test_review_approved_notifies_reviewer_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct();
        $review = $this->createReview($user, $product);

        event(new ReviewApproved($review));

        $this->assertDatabaseNotification(
            $user,
            'review.approved',
            function ($n) use ($review) {
                $this->assertEquals('review.approved', $n->type);
                $this->assertEquals($review->id, $n->data['resource_id']);
                $this->assertEquals($review->product_id, $n->data['product_id']);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'review.approved');
    }

    public function test_review_rejected_notifies_reviewer_in_db_and_broadcast(): void
    {
        $user = $this->createUser('user');
        $product = $this->createProduct();
        $review = $this->createReview($user, $product, false);

        event(new ReviewRejected($review));

        $this->assertDatabaseNotification(
            $user,
            'review.rejected',
            function ($n) use ($review) {
                $this->assertEquals('review.rejected', $n->type);
                $this->assertEquals($review->id, $n->data['resource_id']);
            }
        );
        $this->assertBroadcastTo('private-users.' . $user->id, 'review.rejected');
    }

    public function test_admin_reviewer_is_not_notified_on_approval(): void
    {
        $admin = $this->createUser('admin');
        $product = $this->createProduct();
        $review = $this->createReview($admin, $product);

        event(new ReviewApproved($review));

        $this->assertNoDatabaseNotification($admin, 'review.approved');
        $this->assertEmpty($this->recordedBroadcasts());
    }

    public function test_reviewer_without_review_receives_nothing(): void
    {
        $user = $this->createUser('user');
        $other = $this->createUser('user');
        $product = $this->createProduct();
        $review = $this->createReview($user, $product);

        event(new ReviewApproved($review));

        $this->assertNoDatabaseNotification($other, 'review.approved');
    }
}
