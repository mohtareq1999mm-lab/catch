<?php

declare(strict_types=1);

namespace Tests\Feature\SiteReviews;

use App\Models\SiteReview;

class SiteReviewBugRegressionTest extends SiteReviewTestCase
{
    /** @test */
    public function non_numeric_id_returns_404_not_500(): void
    {
        $this->createAuthenticatedAdmin();

        $this->getJson(self::PREFIX . '/site-reviews/abc')->assertStatus(404);
        $this->patchJson(self::PREFIX . '/site-reviews/abc/approve')->assertStatus(404);
        $this->patchJson(self::PREFIX . '/site-reviews/abc/reject')->assertStatus(404);
    }

    /** @test */
    public function negative_limit_is_normalized_not_409(): void
    {
        $this->createAuthenticatedAdmin();
        SiteReview::factory()->count(2)->create();

        $response = $this->getJson(self::PREFIX . '/site-reviews?limit=-5');

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertSame(1, $response->json('data.per_page'));
    }

    /** @test */
    public function zero_and_non_numeric_limit_fall_back_to_default(): void
    {
        $this->createAuthenticatedAdmin();
        SiteReview::factory()->count(2)->create();

        $this->getJson(self::PREFIX . '/site-reviews?limit=0')->assertStatus(200);
        $this->getJson(self::PREFIX . '/site-reviews?limit=abc')->assertStatus(200);
    }

    /** @test */
    public function oversized_limit_is_capped_at_100(): void
    {
        $this->createAuthenticatedAdmin();
        SiteReview::factory()->count(120)->create();

        $response = $this->getJson(self::PREFIX . '/site-reviews?limit=9999');

        $response->assertStatus(200);
        $this->assertSame(100, $response->json('data.per_page'));
        $this->assertSame(120, $response->json('data.total'));
    }
}
