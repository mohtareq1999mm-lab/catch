<?php

declare(strict_types=1);

namespace Tests\Feature\SiteReviews;

use App\Models\SiteReview;
use Marvel\Database\Models\User;

class SiteReviewRelationshipsTest extends SiteReviewTestCase
{
    /** @test */
    public function user_relationship_returns_the_author(): void
    {
        $author = $this->createCustomer();
        $review = SiteReview::factory()->create(['user_id' => $author->id]);

        $this->assertInstanceOf(User::class, $review->user);
        $this->assertSame($author->id, $review->user->id);
        $this->assertSame($author->name, $review->user->name);
        $this->assertSame($author->email, $review->user->email);
    }

    /** @test */
    public function moderator_relationship_returns_the_admin(): void
    {
        $admin = $this->createAdmin();
        $review = SiteReview::factory()->approved()->create(['moderated_by' => $admin->id]);

        $this->assertInstanceOf(User::class, $review->moderator);
        $this->assertSame($admin->id, $review->moderator->id);
        $this->assertSame($admin->name, $review->moderator->name);
        $this->assertSame($admin->email, $review->moderator->email);
    }

    /** @test */
    public function moderator_relationship_is_null_for_pending_reviews(): void
    {
        $review = SiteReview::factory()->pending()->create();

        $this->assertNull($review->moderated_by);
        $this->assertNull($review->moderator);
    }

    /** @test */
    public function moderator_relationship_uses_moderated_by_column(): void
    {
        $admin = $this->createAdmin();
        $review = SiteReview::factory()->rejected()->create(['moderated_by' => $admin->id]);

        $this->assertSame($admin->id, $review->getAttribute('moderated_by'));
        $this->assertSame($admin->id, $review->moderator->getKey());
    }

    /** @test */
    public function factory_pending_state_has_no_moderator(): void
    {
        $review = SiteReview::factory()->pending()->create();

        $this->assertSame('pending', $review->status->value);
        $this->assertNull($review->moderated_by);
        $this->assertNull($review->moderated_at);
    }

    /** @test */
    public function factory_approved_state_has_moderator(): void
    {
        $review = SiteReview::factory()->approved()->create();

        $this->assertSame('approved', $review->status->value);
        $this->assertNotNull($review->moderated_by);
        $this->assertNotNull($review->moderated_at);
    }

    /** @test */
    public function factory_rejected_state_has_moderator(): void
    {
        $review = SiteReview::factory()->rejected()->create();

        $this->assertSame('rejected', $review->status->value);
        $this->assertNotNull($review->moderated_by);
        $this->assertNotNull($review->moderated_at);
    }
}
