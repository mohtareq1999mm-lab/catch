<?php

declare(strict_types=1);

namespace Tests\Feature\SiteReviews;

use App\Models\SiteReview;

class SiteReviewPublicApiTest extends SiteReviewTestCase
{
    /** @test */
    public function public_api_returns_approved_reviews_only(): void
    {
        $approved = SiteReview::factory()->approved()->create(['title' => 'Approved One']);
        SiteReview::factory()->pending()->create(['title' => 'Pending One']);
        SiteReview::factory()->rejected()->create(['title' => 'Rejected One']);

        $response = $this->getJson(self::GENERAL_PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $data = collect($response->json('data'));
        $titles = $data->pluck('title')->all();

        $this->assertContains('Approved One', $titles);
        $this->assertNotContains('Pending One', $titles);
        $this->assertNotContains('Rejected One', $titles);
    }

    /** @test */
    public function pending_reviews_are_not_publicly_visible(): void
    {
        $pending = SiteReview::factory()->pending()->create(['title' => 'Hidden Pending']);

        $response = $this->getJson(self::GENERAL_PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $this->assertDatabaseHas('site_reviews', ['id' => $pending->id, 'status' => 'pending']);
        $this->assertNotContains('Hidden Pending', collect($response->json('data'))->pluck('title')->all());
    }

    /** @test */
    public function rejected_reviews_are_not_publicly_visible(): void
    {
        $rejected = SiteReview::factory()->rejected()->create(['title' => 'Hidden Rejected']);

        $response = $this->getJson(self::GENERAL_PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $this->assertNotContains('Hidden Rejected', collect($response->json('data'))->pluck('title')->all());
    }

    /** @test */
    public function public_response_exposes_customer_name_and_not_moderation_fields(): void
    {
        $approved = SiteReview::factory()->approved()->create();

        $response = $this->getJson(self::GENERAL_PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $item = collect($response->json('data'))->firstWhere('id', $approved->id);

        $this->assertNotNull($item);
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('rating', $item);
        $this->assertArrayHasKey('title', $item);
        $this->assertArrayHasKey('comment', $item);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertSame($approved->user->name, $item['customer']['name']);

        $this->assertArrayNotHasKey('status', $item);
        $this->assertArrayNotHasKey('moderated_by', $item);
        $this->assertArrayNotHasKey('moderated_at', $item);
        $this->assertArrayNotHasKey('moderator', $item);
    }

    /** @test */
    public function public_endpoint_works_without_authentication(): void
    {
        SiteReview::factory()->approved()->create();

        $response = $this->getJson(self::GENERAL_PREFIX . '/site-reviews');

        $response->assertStatus(200);
    }

    /** @test */
    public function approved_review_can_be_created_by_any_customer_via_factory(): void
    {
        $review = SiteReview::factory()->approved()->create();

        $this->assertSame('approved', $review->status->value);
        $this->assertNotNull($review->moderated_by);
        $this->assertNotNull($review->moderated_at);
    }
}
