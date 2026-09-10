<?php

declare(strict_types=1);

namespace Tests\Feature\SiteReviews;

use App\Models\SiteReview;
use Illuminate\Support\Facades\DB;

class SiteReviewAdminApiTest extends SiteReviewTestCase
{
    /** @test */
    public function admin_can_list_all_site_reviews(): void
    {
        $this->createAuthenticatedAdmin();
        SiteReview::factory()->count(3)->create();

        $response = $this->getJson(self::PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonCount(3, 'data.data');
    }

    /** @test */
    public function admin_dashboard_returns_moderator_name(): void
    {
        $this->createAuthenticatedAdmin();
        $approved = SiteReview::factory()->approved()->create();

        $response = $this->getJson(self::PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $item = collect($response->json('data.data'))->firstWhere('id', $approved->id);

        $this->assertNotNull($item);
        $this->assertSame('approved', $item['status']);
        $this->assertSame($approved->moderator->name, $item['moderator']['name']);
        $this->assertSame($approved->moderator->id, $item['moderator']['id']);
        $this->assertNotNull($item['moderated_at']);
    }

    /** @test */
    public function pending_review_has_no_moderator_in_admin_response(): void
    {
        $this->createAuthenticatedAdmin();
        $pending = SiteReview::factory()->pending()->create();

        $response = $this->getJson(self::PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $item = collect($response->json('data.data'))->firstWhere('id', $pending->id);

        $this->assertNotNull($item);
        $this->assertSame('pending', $item['status']);
        $this->assertNull($item['moderator']);
        $this->assertNull($item['moderated_at']);
    }

    /** @test */
    public function rejected_review_displays_rejecting_admin_name(): void
    {
        $this->createAuthenticatedAdmin();
        $rejected = SiteReview::factory()->rejected()->create();

        $response = $this->getJson(self::PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $item = collect($response->json('data.data'))->firstWhere('id', $rejected->id);

        $this->assertSame('rejected', $item['status']);
        $this->assertSame($rejected->moderator->name, $item['moderator']['name']);
    }

    /** @test */
    public function approved_review_displays_approving_admin_name(): void
    {
        $this->createAuthenticatedAdmin();
        $approved = SiteReview::factory()->approved()->create();

        $response = $this->getJson(self::PREFIX . '/site-reviews');

        $response->assertStatus(200);
        $item = collect($response->json('data.data'))->firstWhere('id', $approved->id);

        $this->assertSame('approved', $item['status']);
        $this->assertSame($approved->moderator->name, $item['moderator']['name']);
    }

    /** @test */
    public function admin_can_filter_reviews_by_status(): void
    {
        $this->createAuthenticatedAdmin();
        SiteReview::factory()->approved()->create(['title' => 'Approved Item']);
        SiteReview::factory()->pending()->create(['title' => 'Pending Item']);
        SiteReview::factory()->rejected()->create(['title' => 'Rejected Item']);

        $response = $this->getJson(self::PREFIX . '/site-reviews?status=pending');

        $response->assertStatus(200);
        $titles = collect($response->json('data.data'))->pluck('title')->all();

        $this->assertContains('Pending Item', $titles);
        $this->assertNotContains('Approved Item', $titles);
        $this->assertNotContains('Rejected Item', $titles);
    }

    /** @test */
    public function admin_can_view_single_review_details(): void
    {
        $admin = $this->createAuthenticatedAdmin();
        $review = SiteReview::factory()->pending()->create();

        $response = $this->getJson(self::PREFIX . "/site-reviews/{$review->id}");

        $response->assertStatus(200);
        $response->assertJsonPath('data.id', $review->id);
        $response->assertJsonPath('data.customer.name', $review->user->name);
        $response->assertJsonPath('data.status', 'pending');
        $response->assertJsonPath('data.moderator', null);
        $response->assertJsonPath('data.moderated_at', null);
    }

    /** @test */
    public function unauthenticated_user_cannot_list_site_reviews(): void
    {
        $response = $this->getJson(self::PREFIX . '/site-reviews');

        $response->assertStatus(401);
    }

    /** @test */
    public function customer_without_permission_cannot_list_site_reviews(): void
    {
        $this->createAuthenticatedCustomer();

        $response = $this->getJson(self::PREFIX . '/site-reviews');

        $response->assertStatus(403);
    }

    /** @test */
    public function customer_without_permission_cannot_view_review_details(): void
    {
        $this->createAuthenticatedCustomer();
        $review = SiteReview::factory()->create();

        $response = $this->getJson(self::PREFIX . "/site-reviews/{$review->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function missing_review_details_returns_404(): void
    {
        $this->createAuthenticatedAdmin();

        $response = $this->getJson(self::PREFIX . '/site-reviews/999999');

        $response->assertStatus(404);
    }

    /** @test */
    public function admin_list_has_no_n_plus_one_for_user_and_moderator(): void
    {
        $this->createAuthenticatedAdmin();
        SiteReview::factory()->count(10)->create();

        DB::enableQueryLog();

        $response = $this->getJson(self::PREFIX . '/site-reviews?limit=10');

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertStatus(200);
        // With eager loading: count + select + users + moderators = 4 queries.
        // Without eager loading this would grow to ~24 queries (N+1).
        $this->assertLessThanOrEqual(8, $queries);
    }
}
