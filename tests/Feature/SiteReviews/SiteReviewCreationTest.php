<?php

declare(strict_types=1);

namespace Tests\Feature\SiteReviews;

use App\Models\SiteReview;

class SiteReviewCreationTest extends SiteReviewTestCase
{
    /** @test */
    public function authenticated_customer_can_create_a_site_review(): void
    {
        $customer = $this->createAuthenticatedCustomer();

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload());

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseHas('site_reviews', [
            'user_id' => $customer->id,
            'rating' => 5,
            'title' => 'Excellent Website',
            'comment' => 'The website is easy to use and the experience is excellent.',
        ]);
    }

    /** @test */
    public function new_site_review_automatically_starts_as_pending(): void
    {
        $this->createAuthenticatedCustomer();

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload());

        $response->assertStatus(201);
        $this->assertDatabaseHas('site_reviews', [
            'id' => $response->json('data.id'),
            'status' => 'pending',
            'moderated_by' => null,
            'moderated_at' => null,
        ]);
    }

    /** @test */
    public function customer_cannot_create_an_approved_review(): void
    {
        $this->createAuthenticatedCustomer();

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload([
            'status' => 'approved',
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('site_reviews', [
            'id' => $response->json('data.id'),
            'status' => 'pending',
        ]);
        $this->assertDatabaseMissing('site_reviews', [
            'id' => $response->json('data.id'),
            'status' => 'approved',
        ]);
    }

    /** @test */
    public function customer_cannot_create_a_rejected_review(): void
    {
        $this->createAuthenticatedCustomer();

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload([
            'status' => 'rejected',
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('site_reviews', [
            'id' => $response->json('data.id'),
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function customer_cannot_set_moderated_by(): void
    {
        $this->createAuthenticatedCustomer();

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload([
            'moderated_by' => 999,
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('site_reviews', [
            'id' => $response->json('data.id'),
            'moderated_by' => null,
        ]);
    }

    /** @test */
    public function customer_cannot_set_moderated_at(): void
    {
        $this->createAuthenticatedCustomer();

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload([
            'moderated_at' => '2026-08-10 10:30:00',
        ]));

        $response->assertStatus(201);
        $this->assertDatabaseHas('site_reviews', [
            'id' => $response->json('data.id'),
            'moderated_at' => null,
        ]);
    }

    /** @test */
    public function unauthenticated_customer_cannot_create_a_site_review(): void
    {
        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload());

        $response->assertStatus(401);
    }

    /** @test */
    public function invalid_ratings_are_rejected(): void
    {
        $this->createAuthenticatedCustomer();

        foreach ([0, 6, -1, 'abc'] as $invalidRating) {
            $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload([
                'rating' => $invalidRating,
            ]));

            $response->assertStatus(422);
        }
    }

    /** @test */
    public function missing_rating_is_rejected(): void
    {
        $this->createAuthenticatedCustomer();

        $payload = $this->createReviewPayload();
        unset($payload['rating']);

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function missing_comment_is_rejected(): void
    {
        $this->createAuthenticatedCustomer();

        $payload = $this->createReviewPayload();
        unset($payload['comment']);

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $payload);

        $response->assertStatus(422);
    }

    /** @test */
    public function title_is_optional(): void
    {
        $this->createAuthenticatedCustomer();

        $payload = $this->createReviewPayload();
        unset($payload['title']);

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $payload);

        $response->assertStatus(201);
    }

    /** @test */
    public function created_review_response_does_not_expose_moderation_fields(): void
    {
        $this->createAuthenticatedCustomer();

        $response = $this->postJson(self::GENERAL_PREFIX . '/site-reviews', $this->createReviewPayload());

        $response->assertStatus(201);
        $data = $response->json('data');
        $this->assertArrayNotHasKey('status', $data);
        $this->assertArrayNotHasKey('moderator', $data);
        $this->assertArrayNotHasKey('moderated_by', $data);
        $this->assertArrayNotHasKey('moderated_at', $data);
    }

    /** @test */
    public function review_can_be_created_without_a_title(): void
    {
        $review = SiteReview::factory()->create([
            'title' => null,
        ]);

        $this->assertNull($review->title);
    }
}
