<?php

namespace Database\Seeders;

use App\Enums\SiteReviewStatus;
use App\Models\SiteReview;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Marvel\Database\Models\User;

class SiteReviewSeeder extends Seeder
{
    public function run(): void
    {
        $customers = User::query()
            ->where('type', 'user')
            ->where('is_active', true)
            ->get();

        if ($customers->isEmpty()) {
            $customers = User::query()->create([
                'name' => 'Site Reviewer',
                'email' => 'site-reviewer@demo.com',
                'password' => Hash::make('password'),
                'is_active' => true,
                'phone_number' => '01000000000',
                'email_verified_at' => now(),
                'type' => 'user',
            ]);
            $customers = collect([$customers]);
        }

        $moderator = User::query()
            ->where('type', 'admin')
            ->where('is_active', true)
            ->first();

        if (!$moderator) {
            $moderator = User::query()->create([
                'name' => 'Shop Admin',
                'email' => 'site-reviews-admin@demo.com',
                'password' => Hash::make('password'),
                'is_active' => true,
                'phone_number' => '01000000001',
                'email_verified_at' => now(),
                'type' => 'admin',
            ]);
        }

        $ratings = [5, 5, 5, 4, 4, 3, 3, 2, 1];

        $sampleReviews = [
            ['rating' => 5, 'title' => 'Excellent Website', 'comment' => 'The website is easy to use and the experience is excellent.'],
            ['rating' => 5, 'title' => 'Very User Friendly', 'comment' => 'I love how simple it is to find products and complete my order.'],
            ['rating' => 5, 'title' => 'Great Experience', 'comment' => 'Smooth checkout and fast loading pages. Highly recommended.'],
            ['rating' => 4, 'title' => 'Good Website', 'comment' => 'Overall a pleasant experience, though a few pages load slowly.'],
            ['rating' => 4, 'title' => 'Nice Design', 'comment' => 'The design is clean and modern. Navigation is straightforward.'],
            ['rating' => 3, 'title' => 'Average Experience', 'comment' => 'The website works fine but could be faster on mobile.'],
            ['rating' => 3, 'title' => 'Decent Platform', 'comment' => 'Good features, but the search could be improved.'],
            ['rating' => 2, 'title' => 'Needs Improvement', 'comment' => 'The website is slow and sometimes unresponsive.'],
            ['rating' => 1, 'title' => 'Poor Experience', 'comment' => 'Frequent errors during checkout made it frustrating to use.'],
        ];

        $statusDistribution = [
            SiteReviewStatus::APPROVED->value,
            SiteReviewStatus::APPROVED->value,
            SiteReviewStatus::APPROVED->value,
            SiteReviewStatus::APPROVED->value,
            SiteReviewStatus::APPROVED->value,
            SiteReviewStatus::PENDING->value,
            SiteReviewStatus::PENDING->value,
            SiteReviewStatus::REJECTED->value,
        ];

        foreach ($sampleReviews as $index => $review) {
            $status = $statusDistribution[$index] ?? SiteReviewStatus::APPROVED->value;

            SiteReview::query()->create([
                'user_id' => $customers->random()->id,
                'rating' => $review['rating'],
                'title' => $review['title'],
                'comment' => $review['comment'],
                'status' => $status,
                'moderated_by' => $status === SiteReviewStatus::PENDING->value ? null : $moderator->id,
                'moderated_at' => $status === SiteReviewStatus::PENDING->value ? null : now()->subDays(random_int(1, 15)),
            ]);
        }

        $this->command?->info('SiteReviewSeeder completed successfully. Created website reviews.');
    }
}
