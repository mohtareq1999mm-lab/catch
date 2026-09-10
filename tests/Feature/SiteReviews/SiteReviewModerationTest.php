<?php

declare(strict_types=1);

namespace Tests\Feature\SiteReviews;

use App\Models\SiteReview;

class SiteReviewModerationTest extends SiteReviewTestCase
{
    /** @test */
    public function authorized_admin_can_approve_a_pending_review(): void
    {
        $admin = $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('site_reviews', [
            'id' => $review->id,
            'status' => 'approved',
        ]);
    }

    /** @test */
    public function approval_changes_status_to_approved(): void
    {
        $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");

        $this->assertSame('approved', $review->fresh()->status->value);
    }

    /** @test */
    public function approval_stores_admin_id_in_moderated_by(): void
    {
        $admin = $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");

        $this->assertSame($admin->id, $review->fresh()->moderated_by);
    }

    /** @test */
    public function approval_stores_timestamp_in_moderated_at(): void
    {
        $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");

        $this->assertNotNull($review->fresh()->moderated_at);
    }

    /** @test */
    public function authorized_admin_can_reject_a_pending_review(): void
    {
        $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('site_reviews', [
            'id' => $review->id,
            'status' => 'rejected',
        ]);
    }

    /** @test */
    public function rejection_changes_status_to_rejected(): void
    {
        $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $this->assertSame('rejected', $review->fresh()->status->value);
    }

    /** @test */
    public function rejection_stores_admin_id_in_moderated_by(): void
    {
        $admin = $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $this->assertSame($admin->id, $review->fresh()->moderated_by);
    }

    /** @test */
    public function rejection_stores_timestamp_in_moderated_at(): void
    {
        $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $this->assertNotNull($review->fresh()->moderated_at);
    }

    /** @test */
    public function unauthenticated_user_cannot_approve(): void
    {
        $review = SiteReview::factory()->pending()->create();

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");

        $response->assertStatus(401);
        $this->assertSame('pending', $review->fresh()->status->value);
    }

    /** @test */
    public function unauthenticated_user_cannot_reject(): void
    {
        $review = SiteReview::factory()->pending()->create();

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $response->assertStatus(401);
        $this->assertSame('pending', $review->fresh()->status->value);
    }

    /** @test */
    public function customer_without_permission_cannot_approve(): void
    {
        $this->createAuthenticatedCustomer();
        $review = SiteReview::factory()->pending()->create();

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");

        $response->assertStatus(403);
        $this->assertSame('pending', $review->fresh()->status->value);
    }

    /** @test */
    public function customer_without_permission_cannot_reject(): void
    {
        $this->createAuthenticatedCustomer();
        $review = SiteReview::factory()->pending()->create();

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $response->assertStatus(403);
        $this->assertSame('pending', $review->fresh()->status->value);
    }

    /** @test */
    public function cannot_approve_an_already_approved_review(): void
    {
        $admin = $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->approved()->create();
        $originalModerator = $review->moderated_by;

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");

        $response->assertStatus(404);
        $this->assertSame('approved', $review->fresh()->status->value);
        $this->assertSame($originalModerator, $review->fresh()->moderated_by);
    }

    /** @test */
    public function cannot_reject_an_already_rejected_review(): void
    {
        $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->rejected()->create();

        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $response->assertStatus(404);
        $this->assertSame('rejected', $review->fresh()->status->value);
    }

    /** @test */
    public function approve_and_reject_revert_is_not_allowed(): void
    {
        $admin = $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/approve");
        $response = $this->patchJson(self::PREFIX . "/site-reviews/{$review->id}/reject");

        $response->assertStatus(404);
        $this->assertSame('approved', $review->fresh()->status->value);
        $this->assertSame($admin->id, $review->fresh()->moderated_by);
    }

    /** @test */
    public function approval_of_missing_review_returns_404(): void
    {
        $this->createAuthenticatedAdmin();

        $response = $this->patchJson(self::PREFIX . '/site-reviews/999999/approve');

        $response->assertStatus(404);
    }
}
